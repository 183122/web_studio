<?php

namespace App\Domain\Integrations\Google;

/**
 * Least privilege: only the features the admin ticks are requested.
 * - sheets: read/write spreadsheets the user explicitly links by URL (`spreadsheets` scope).
 * - drive: `drive.file` — only files and folders this app creates; never the user's whole Drive.
 */
final class GoogleScopes
{
    public const IDENTITY = ['openid', 'email'];

    public const FEATURES = [
        'sheets' => 'https://www.googleapis.com/auth/spreadsheets',
        'drive' => 'https://www.googleapis.com/auth/drive.file',
    ];

    /** @param list<string> $features */
    public static function for(array $features): array
    {
        $scopes = self::IDENTITY;
        foreach ($features as $f) {
            if (isset(self::FEATURES[$f])) {
                $scopes[] = self::FEATURES[$f];
            }
        }

        return array_values(array_unique($scopes));
    }

    public static function features(array $scopes): array
    {
        return array_keys(array_filter(self::FEATURES, fn ($s) => in_array($s, $scopes, true)));
    }
}
