<?php

namespace App\Domain\Platform;

use App\Models\PlatformSetting;
use App\Models\User;

/**
 * Google OAuth client used by the (Phase 5) Google Sheets/Drive integration.
 * Source order: environment variables (GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET) win, so operators who
 * manage secrets in their deployment platform keep full control; otherwise the value a platform admin
 * saved in the admin settings (encrypted at rest). The secret is only ever read server-side.
 */
class GoogleOAuthSettings
{
    public const KEY = 'google_oauth';

    public const CLIENT_ID_PATTERN = '/^[0-9]{6,30}-[a-z0-9]{10,64}\.apps\.googleusercontent\.com$/';

    public const SECRET_PATTERN = '/^[A-Za-z0-9_\-]{20,100}$/';

    /** @return array{clientId: string, clientSecret: string, source: 'environment'|'database'}|null */
    public function credentials(): ?array
    {
        $envId = (string) config('services.google.client_id');
        $envSecret = (string) config('services.google.client_secret');
        if ($envId !== '' && $envSecret !== '') {
            return ['clientId' => $envId, 'clientSecret' => $envSecret, 'source' => 'environment'];
        }
        $row = PlatformSetting::query()->where('key', self::KEY)->first();
        if ($row === null || empty($row->value['clientId']) || empty($row->value['clientSecret'])) {
            return null;
        }

        return ['clientId' => $row->value['clientId'], 'clientSecret' => $row->value['clientSecret'], 'source' => 'database'];
    }

    public function environmentManaged(): bool
    {
        return (string) config('services.google.client_id') !== '' || (string) config('services.google.client_secret') !== '';
    }

    /** Values to register in Google Cloud Console for this deployment. */
    public static function consoleValues(): array
    {
        $origin = (string) config('studio.frontend_url');

        return [
            'authorizedOrigin' => $origin,
            'redirectUri' => $origin.'/api/v1/integrations/google/callback',
            'scopes' => ['openid', 'email', 'https://www.googleapis.com/auth/drive.file', 'https://www.googleapis.com/auth/spreadsheets'],
        ];
    }

    /** Safe summary for the admin UI: never includes the secret. */
    public function summary(): array
    {
        $row = PlatformSetting::query()->where('key', self::KEY)->first();
        $creds = $this->credentials();
        $secret = $creds['clientSecret'] ?? null;

        return [
            'configured' => $creds !== null,
            'source' => $creds['source'] ?? null,
            'environmentManaged' => $this->environmentManaged(),
            'clientId' => $creds['clientId'] ?? ($row?->value['clientId'] ?? null),
            'secretLast4' => $secret ? substr($secret, -4) : null,
            'updatedAt' => $creds && $creds['source'] === 'database' ? $row?->updated_at?->toIso8601String() : null,
            'updatedBy' => $creds && $creds['source'] === 'database' && $row?->updated_by ? User::query()->whereKey($row->updated_by)->value('name') : null,
        ] + self::consoleValues();
    }

    /** Saves the client; a null secret keeps the stored one (rotate the ID label without re-entering). */
    public function save(string $clientId, ?string $clientSecret, User $by): void
    {
        $row = PlatformSetting::query()->firstOrNew(['key' => self::KEY]);
        $current = $row->exists ? $row->value : [];
        $secret = $clientSecret ?? ($current['clientSecret'] ?? null);
        if ($secret === null) {
            throw new \InvalidArgumentException('client secret required');
        }
        $row->fill(['value' => ['clientId' => $clientId, 'clientSecret' => $secret], 'updated_by' => $by->id])->save();
    }

    public function clear(): void
    {
        PlatformSetting::query()->where('key', self::KEY)->delete();
    }
}
