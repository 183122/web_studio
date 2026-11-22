<?php

namespace App\Domain\Publishing;

/** Hostname normalisation and validation (no IPs, no wildcards, punycode for IDNs). */
final class Hostnames
{
    public static function normalize(string $host): ?string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('#^https?://#', '', $host) ?? $host;
        $host = rtrim(explode('/', $host)[0], '.');
        $host = explode(':', $host)[0];
        if ($host === '' || strlen($host) > 253) {
            return null;
        }
        if (preg_match('/[^\x00-\x7F]/', $host) === 1) {
            $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46) : false;
            if ($ascii === false) {
                return null;
            }
            $host = $ascii;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }
        $labels = explode('.', $host);
        if (count($labels) < 2) {
            return null;
        }
        foreach ($labels as $label) {
            if (preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)$/', $label) !== 1) {
                return null;
            }
        }

        return $host;
    }

    public static function baseDomain(): string
    {
        return config('studio.publishing.base_domain');
    }

    public static function isPlatformHost(string $host): bool
    {
        $hosts = config('studio.publishing.platform_hosts');
        foreach ([config('app.url'), config('studio.frontend_url')] as $url) {
            if ($h = parse_url((string) $url, PHP_URL_HOST)) {
                $hosts[] = strtolower($h);
            }
        }

        return in_array(strtolower($host), $hosts, true);
    }

    public static function subdomainHost(string $slug): string
    {
        return $slug.'.'.self::baseDomain();
    }

    /** Slug of a platform subdomain host, or null if the host is not directly under the base domain. */
    public static function slugOf(string $host): ?string
    {
        $suffix = '.'.self::baseDomain();
        if (! str_ends_with($host, $suffix)) {
            return null;
        }
        $slug = substr($host, 0, -strlen($suffix));

        return str_contains($slug, '.') || $slug === '' ? null : $slug;
    }

    public static function isUnderBaseDomain(string $host): bool
    {
        return $host === self::baseDomain() || str_ends_with($host, '.'.self::baseDomain());
    }

    public static function validSlug(string $slug): bool
    {
        return preg_match('/^[a-z0-9](?:[a-z0-9-]{1,38}[a-z0-9])$/', $slug) === 1 && ! str_contains($slug, '--');
    }
}
