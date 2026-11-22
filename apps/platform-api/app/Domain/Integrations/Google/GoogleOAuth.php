<?php

namespace App\Domain\Integrations\Google;

use App\Domain\Platform\GoogleOAuthSettings;
use App\Models\GoogleConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Authorization-code flow with PKCE (S256) and a single-use, user-bound `state`.
 * Refresh tokens and access tokens are stored encrypted; refresh runs under a per-connection lock.
 */
class GoogleOAuth
{
    private const STATE_TTL = 600;

    public function __construct(private readonly GoogleOAuthSettings $settings, private readonly AuditLogger $audit) {}

    private function client(): array
    {
        $creds = $this->settings->credentials();
        if ($creds === null) {
            throw new GoogleApiException('not_configured', 'Google OAuth client is not configured');
        }

        return $creds;
    }

    public static function redirectUri(): string
    {
        return GoogleOAuthSettings::consoleValues()['redirectUri'];
    }

    /** @param list<string> $features */
    public function authorizationUrl(User $user, Workspace $workspace, array $features): string
    {
        $client = $this->client();
        $state = Str::random(40);
        $verifier = Str::random(64);
        Cache::put("google_oauth_state:{$state}", [
            'user_id' => $user->id, 'workspace_id' => $workspace->id, 'verifier' => $verifier, 'features' => $features,
        ], self::STATE_TTL);

        return GoogleEndpoints::url('auth').'?'.http_build_query([
            'client_id' => $client['clientId'],
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', GoogleScopes::for($features)),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'false',
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);
    }

    /** Consumes the state (single use) and returns its payload if it belongs to this user. */
    public function consumeState(string $state, User $user): ?array
    {
        if (strlen($state) !== 40) {
            return null;
        }
        $data = Cache::pull("google_oauth_state:{$state}");

        return is_array($data) && $data['user_id'] === $user->id ? $data : null;
    }

    public function completeConnection(array $state, string $code, User $user): GoogleConnection
    {
        $client = $this->client();
        $res = Http::asForm()->timeout((int) config('services.google.timeout'))->post(GoogleEndpoints::url('token'), [
            'code' => $code,
            'client_id' => $client['clientId'],
            'client_secret' => $client['clientSecret'],
            'redirect_uri' => self::redirectUri(),
            'grant_type' => 'authorization_code',
            'code_verifier' => $state['verifier'],
        ]);
        if (! $res->successful() || ! is_string($res->json('access_token'))) {
            throw new GoogleApiException('auth', 'token exchange failed: '.($res->json('error') ?? $res->status()), $res->status());
        }
        $granted = array_values(array_filter(explode(' ', (string) $res->json('scope'))));
        $info = Http::withToken($res->json('access_token'))->timeout((int) config('services.google.timeout'))->get(GoogleEndpoints::url('userinfo'));

        $existing = GoogleConnection::query()->where('workspace_id', $state['workspace_id'])->first();
        $refresh = $res->json('refresh_token') ?? ($existing?->google_sub === $info->json('sub') ? $existing?->refresh_token : null);
        if (! is_string($refresh) || $refresh === '') {
            throw new GoogleApiException('auth', 'Google did not return a refresh token');
        }
        $connection = GoogleConnection::query()->updateOrCreate(['workspace_id' => $state['workspace_id']], [
            'connected_by' => $user->id,
            'google_sub' => $info->json('sub'),
            'email' => $info->json('email'),
            'scopes' => $granted,
            'access_token' => $res->json('access_token'),
            'refresh_token' => $refresh,
            'expires_at' => now()->addSeconds(max(60, (int) $res->json('expires_in', 3600)) - 60),
            'status' => 'active',
            'last_error' => null,
        ]);
        $workspace = Workspace::query()->find($state['workspace_id']);
        $this->audit->record('google.connected', $workspace, ['email' => $connection->email, 'features' => GoogleScopes::features($granted)]);

        return $connection;
    }

    /** Valid access token, refreshing under a lock. Marks the connection revoked on invalid_grant. */
    public function accessToken(GoogleConnection $connection): string
    {
        if ($connection->status !== 'active') {
            throw new GoogleApiException('auth', 'connection is not active');
        }
        if ($connection->access_token && $connection->expires_at && $connection->expires_at->isFuture()) {
            return $connection->access_token;
        }

        return Cache::lock("google_refresh:{$connection->id}", 30)->block(20, function () use ($connection) {
            $connection->refresh();
            if ($connection->access_token && $connection->expires_at?->isFuture()) {
                return $connection->access_token;
            }
            $client = $this->client();
            $res = Http::asForm()->timeout((int) config('services.google.timeout'))->post(GoogleEndpoints::url('token'), [
                'client_id' => $client['clientId'], 'client_secret' => $client['clientSecret'],
                'refresh_token' => $connection->refresh_token, 'grant_type' => 'refresh_token',
            ]);
            if ($res->json('error') === 'invalid_grant' || $res->status() === 401) {
                $connection->forceFill(['status' => 'revoked', 'last_error' => 'Google revoked access (invalid_grant)', 'access_token' => null])->save();
                throw new GoogleApiException('auth', 'refresh token revoked', $res->status());
            }
            if (! $res->successful()) {
                throw new GoogleApiException($res->status() === 429 ? 'rate_limited' : 'transient', 'token refresh failed', $res->status());
            }
            $connection->forceFill([
                'access_token' => $res->json('access_token'),
                'expires_at' => now()->addSeconds(max(60, (int) $res->json('expires_in', 3600)) - 60),
                'refresh_token' => $res->json('refresh_token') ?? $connection->refresh_token,
            ])->save();

            return $connection->access_token;
        });
    }

    /** Revokes at Google (best effort) and deletes tokens locally. Local deletion always happens. */
    public function disconnect(GoogleConnection $connection): bool
    {
        $revoked = false;
        $token = $connection->refresh_token ?? $connection->access_token;
        if ($token) {
            try {
                $revoked = Http::asForm()->timeout(10)->post(GoogleEndpoints::url('revoke'), ['token' => $token])->successful();
            } catch (\Throwable) {
                $revoked = false;
            }
        }
        $workspace = Workspace::query()->find($connection->workspace_id);
        $connection->delete();
        $this->audit->record('google.disconnected', $workspace, ['email' => $connection->email, 'revoked_at_google' => $revoked]);

        return $revoked;
    }
}
