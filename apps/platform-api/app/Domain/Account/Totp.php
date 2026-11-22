<?php

namespace App\Domain\Account;

/** RFC 6238 TOTP (HMAC-SHA1, 30 s, 6 digits) with RFC 4648 base32 secrets. */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function newSecret(): string
    {
        $bytes = random_bytes(20);
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    private static function decode(string $secret): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($secret, '='))) as $c) {
            $i = strpos(self::ALPHABET, $c);
            if ($i === false) {
                return '';
            }
            $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }

    public static function code(string $secret, int $timestep): string
    {
        $hash = hash_hmac('sha1', pack('J', $timestep), self::decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $bin = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($bin % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    /** Matching time step within ±1 step (clock drift), or null. */
    public static function verify(string $secret, string $code, ?int $now = null): ?int
    {
        if (! preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $step = intdiv($now ?? time(), 30);
        foreach ([$step, $step - 1, $step + 1] as $s) {
            if (hash_equals(self::code($secret, $s), $code)) {
                return $s;
            }
        }

        return null;
    }

    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account).'?'.http_build_query(['secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => 6, 'period' => 30]);
    }
}
