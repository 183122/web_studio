<?php

namespace App\Domain\Integrations\Webhooks;

use App\Support\ApiException;

/**
 * SSRF protection for outbound requests to user-supplied URLs:
 * https only (http only when explicitly allowed for local dev), ports 443/80/8443, no credentials in
 * the URL, every resolved address must be public (no loopback, private, link-local/metadata,
 * CGNAT, multicast, reserved, IPv6 ULA). The caller pins the connection to the validated IP
 * (no DNS rebinding) and must not follow redirects.
 */
class EgressGuard
{
    /** @var callable(string): list<string> */
    private $resolver;

    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? static function (string $host): array {
            $ips = gethostbynamel($host) ?: [];
            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $r) {
                if (! empty($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }

            return $ips;
        };
    }

    private static function allowPrivate(): bool
    {
        return (bool) config('studio.egress.allow_private') && ! app()->isProduction();
    }

    /** @return array{url: string, host: string, port: int, ip: string} */
    public function check(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(trim($parts['host'] ?? '', '[]'));
        if (! in_array($scheme, ['https', 'http'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw self::deny('URL ต้องเป็น https:// และไม่มีชื่อผู้ใช้/รหัสผ่าน');
        }
        if ($scheme === 'http' && ! self::allowPrivate()) {
            throw self::deny('อนุญาตเฉพาะ https://');
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (! in_array($port, [443, 80, 8443], true) && ! self::allowPrivate()) {
            throw self::deny('อนุญาตเฉพาะพอร์ต 443, 80 หรือ 8443');
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->resolver)($host);
        if ($ips === []) {
            throw self::deny('ไม่พบที่อยู่ของโดเมนนี้ (DNS)');
        }
        foreach ($ips as $ip) {
            if (! self::isPublic($ip) && ! self::allowPrivate()) {
                throw self::deny('ไม่อนุญาตให้ส่งไปยังเครือข่ายภายในหรือที่อยู่สงวน');
            }
        }

        return ['url' => $url, 'host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    public static function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false; // private, loopback, link-local (169.254/16 incl. cloud metadata), reserved
        }
        if (str_contains($ip, ':')) {
            $lower = strtolower($ip);

            return ! (str_starts_with($lower, 'fc') || str_starts_with($lower, 'fd') || str_starts_with($lower, 'fe8') || str_starts_with($lower, 'ff') || str_starts_with($lower, '::ffff:') || $lower === '::1');
        }
        $long = ip2long($ip);
        foreach ([['100.64.0.0', 10], ['192.0.0.0', 24], ['198.18.0.0', 15], ['224.0.0.0', 4], ['0.0.0.0', 8]] as [$net, $bits]) {
            $mask = -1 << (32 - $bits);
            if (($long & $mask) === (ip2long($net) & $mask)) {
                return false;
            }
        }

        return true;
    }

    private static function deny(string $message): ApiException
    {
        return ApiException::unprocessable('url_not_allowed', $message);
    }
}
