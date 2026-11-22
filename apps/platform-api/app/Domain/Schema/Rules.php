<?php

namespace App\Domain\Schema;

/**
 * Mirror of packages/app-schema/src/rules.ts. Keep both in sync; parity fixtures enforce it.
 */
final class Rules
{
    public const MAX_DEPTH = 32;

    public const MAX_COMPONENTS = 5000;

    public const MAX_PAGES = 100;

    public const MAX_URL_LENGTH = 2048;

    private const LENGTH_UNIT = '-?(?:\d{1,4}(?:\.\d{1,3})?)(?:px|rem|em|%|vh|vw)';

    public static function lengthPattern(): string
    {
        $atom = '(?:'.self::LENGTH_UNIT.'|0|auto)';

        return '/^(?:'.$atom.'|\$radius)$/';
    }

    public static function boxPattern(): string
    {
        $atom = '(?:'.self::LENGTH_UNIT.'|0|auto)';

        return '/^'.$atom.'(?: '.$atom.'){0,3}$/';
    }

    public const HEX_COLOR = '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/';

    public const COLOR_TOKEN = '/^\$(primary|secondary|accent|background|surface|text|muted)$/';

    public const ASSET_REF = '/^asset:[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/';

    public const HTTPS_URL = '/^https:\/\/[A-Za-z0-9\-._~:\/?#\[\]@!$&\'()*+,;=%]{1,2040}$/';

    public const HREF_ANCHOR = '/^#[A-Za-z0-9_-]{0,64}$/';

    public const HREF_PATH = '/^\/(?!\/)[A-Za-z0-9\-._~\/%?#=&+]{0,2000}$/';

    public const HREF_HTTP = '/^https?:\/\/[A-Za-z0-9\-._~:\/?#\[\]@!$&\'()*+,;=%]{1,2040}$/';

    public const HREF_MAILTO = '/^mailto:[A-Za-z0-9\-._~@%+?=&]{3,320}$/';

    public const HREF_TEL = '/^tel:[0-9+\-() ]{3,32}$/';

    public const SLUG = '/^[a-z][a-z0-9-]{0,63}$/';

    public const VIDEO_ID = '/^[A-Za-z0-9_-]{1,32}$/';

    public const FORBIDDEN_CONTROL = '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}]/u';

    public const NEWLINE = '/[\r\n]/';

    public static function isSafeHref(string $value): bool
    {
        if ($value === '' || strlen($value) > self::MAX_URL_LENGTH) {
            return false;
        }
        foreach ([self::HREF_ANCHOR, self::HREF_PATH, self::HREF_HTTP, self::HREF_MAILTO, self::HREF_TEL] as $p) {
            if (preg_match($p, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    public static function isSafeImageSource(string $value): bool
    {
        return $value === '' || preg_match(self::ASSET_REF, $value) === 1 || preg_match(self::HTTPS_URL, $value) === 1;
    }
}
