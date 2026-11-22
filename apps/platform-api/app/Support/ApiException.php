<?php

namespace App\Support;

use RuntimeException;

/** Domain error rendered as {"error": {"code", "message", "details"}} with the given HTTP status. */
class ApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function conflict(string $code, string $message, array $details = []): self
    {
        return new self(409, $code, $message, $details);
    }

    public static function unprocessable(string $code, string $message, array $details = []): self
    {
        return new self(422, $code, $message, $details);
    }

    public static function quota(string $entitlement, int $limit): self
    {
        return new self(422, 'quota_exceeded', "แผนปัจจุบันของคุณจำกัด {$entitlement} ไว้ที่ {$limit}", ['entitlement' => $entitlement, 'limit' => $limit]);
    }
}
