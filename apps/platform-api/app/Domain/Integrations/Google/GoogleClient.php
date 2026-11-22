<?php

namespace App\Domain\Integrations\Google;

use App\Models\GoogleConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Authorized HTTP calls to Google APIs with error classification. Tokens never leave this class. */
class GoogleClient
{
    public function __construct(private readonly GoogleOAuth $oauth) {}

    public function connectionFor(string $workspaceId, string $feature): GoogleConnection
    {
        $c = GoogleConnection::query()->where('workspace_id', $workspaceId)->first();
        if ($c === null) {
            throw new GoogleApiException('not_connected', 'workspace has no Google connection');
        }
        if ($c->status !== 'active') {
            throw new GoogleApiException('auth', 'connection is '.$c->status);
        }
        if (! $c->hasScope(GoogleScopes::FEATURES[$feature])) {
            throw new GoogleApiException('scope_missing', "connection lacks {$feature} scope");
        }

        return $c;
    }

    public function request(GoogleConnection $c): PendingRequest
    {
        $c->forceFill(['last_used_at' => now()])->saveQuietly();

        return Http::withToken($this->oauth->accessToken($c))->acceptJson()->timeout((int) config('services.google.timeout'));
    }

    /** @param callable(PendingRequest): Response $send */
    public function call(GoogleConnection $c, callable $send): Response
    {
        try {
            $res = $send($this->request($c));
        } catch (ConnectionException $e) {
            throw new GoogleApiException('transient', 'connection failed');
        }
        if ($res->successful()) {
            return $res;
        }
        $status = $res->status();
        $reason = (string) ($res->json('error.status') ?? $res->json('error.message') ?? $status);
        if ($status === 401) {
            $c->forceFill(['status' => 'revoked', 'last_error' => 'Google returned 401', 'access_token' => null])->save();
        }
        throw new GoogleApiException(match (true) {
            $status === 401 => 'auth',
            $status === 403 && str_contains(strtolower($reason), 'rate') => 'rate_limited',
            $status === 403 => 'permission',
            $status === 404 => 'not_found',
            $status === 429 => 'rate_limited',
            $status >= 500 => 'transient',
            default => 'invalid',
        }, mb_substr($reason, 0, 200), $status);
    }
}
