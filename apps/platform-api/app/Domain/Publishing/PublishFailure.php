<?php

namespace App\Domain\Publishing;

use RuntimeException;

/** A publish step failed; the previous release stays live. */
class PublishFailure extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
