<?php

namespace App\Domain\Billing;

use App\Support\ApiException;

/** `t=<unix>,v1=<hex HMAC-SHA256(secret, "<t>.<body>")>` with a replay window. */
final class WebhookSignature
{
    public static function sign(string $secret, string $body, int $t): string
    {
        return "t={$t},v1=".hash_hmac('sha256', $t.'.'.$body, $secret);
    }

    public static function verify(string $secret, string $body, ?string $header, int $tolerance): void
    {
        if (! is_string($header) || ! preg_match('/^t=(\d{9,11}),v1=([0-9a-f]{64})$/', $header, $m)) {
            throw new ApiException(400, 'invalid_signature', 'ลายเซ็นไม่ถูกต้อง');
        }
        if (abs(time() - (int) $m[1]) > $tolerance) {
            throw new ApiException(400, 'stale_webhook', 'เหตุการณ์เก่าเกินกำหนด (ป้องกันการส่งซ้ำ)');
        }
        if (! hash_equals(hash_hmac('sha256', $m[1].'.'.$body, $secret), $m[2])) {
            throw new ApiException(400, 'invalid_signature', 'ลายเซ็นไม่ถูกต้อง');
        }
    }
}
