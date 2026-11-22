<?php

namespace App\Domain\Ai;

use RuntimeException;

/**
 * Provider failure, classified. `retryable` failures (outage, rate limit, timeout) let the
 * gateway fall back to the offline provider; the rest are reported to the user.
 */
class AiProviderException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly bool $retryable, public readonly int $inputTokens = 0, public readonly int $outputTokens = 0)
    {
        parent::__construct($message);
    }
}
