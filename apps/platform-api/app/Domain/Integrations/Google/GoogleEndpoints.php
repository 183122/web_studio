<?php

namespace App\Domain\Integrations\Google;

use RuntimeException;

/** Google endpoint URLs. A fake base (local/E2E) replaces the hosts but never in production. */
final class GoogleEndpoints
{
    private const REAL = [
        'auth' => 'https://accounts.google.com/o/oauth2/v2/auth',
        'token' => 'https://oauth2.googleapis.com/token',
        'revoke' => 'https://oauth2.googleapis.com/revoke',
        'userinfo' => 'https://openidconnect.googleapis.com/v1/userinfo',
        'sheets' => 'https://sheets.googleapis.com/v4',
        'drive' => 'https://www.googleapis.com/drive/v3',
        'upload' => 'https://www.googleapis.com/upload/drive/v3',
    ];

    private const FAKE_PATHS = [
        'auth' => '/o/oauth2/v2/auth', 'token' => '/token', 'revoke' => '/revoke', 'userinfo' => '/v1/userinfo',
        'sheets' => '/sheets/v4', 'drive' => '/drive/v3', 'upload' => '/upload/drive/v3',
    ];

    public static function url(string $name): string
    {
        $fake = (string) config('services.google.fake_base');
        if ($fake === '') {
            return self::REAL[$name];
        }
        if (app()->isProduction()) {
            throw new RuntimeException('GOOGLE_FAKE_BASE must not be set in production');
        }

        return rtrim($fake, '/').self::FAKE_PATHS[$name];
    }
}
