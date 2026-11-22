<?php

namespace App\Domain\Account;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** 2FA state for a user: secret, confirmation, one-time recovery codes, replay protection. */
class TwoFactor
{
    public static function enabled(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null && $user->two_factor_secret !== null;
    }

    /** Verifies a TOTP code once (the same time step cannot be reused) or a recovery code (consumed). */
    public static function check(User $user, ?string $code, ?string $recovery): bool
    {
        if ($code !== null && $user->two_factor_secret) {
            $step = Totp::verify($user->two_factor_secret, $code);
            if ($step === null) {
                return false;
            }

            // Replay protection: each accepted step can be used once per user.
            return Cache::add("totp_used:{$user->id}:{$step}", 1, 120);
        }
        if ($recovery !== null) {
            $hashes = json_decode((string) $user->two_factor_recovery_codes, true) ?: [];
            $h = hash('sha256', strtoupper(trim($recovery)));
            if (! in_array($h, $hashes, true)) {
                return false;
            }
            $user->forceFill(['two_factor_recovery_codes' => json_encode(array_values(array_diff($hashes, [$h])))])->save();

            return true;
        }

        return false;
    }

    /** @return list<string> plain codes (shown once) */
    public static function newRecoveryCodes(User $user): array
    {
        $codes = array_map(fn () => strtoupper(Str::random(5).'-'.Str::random(5)), range(1, 10));
        $user->forceFill(['two_factor_recovery_codes' => json_encode(array_map(fn ($c) => hash('sha256', $c), $codes))])->save();

        return $codes;
    }
}
