<?php

namespace App\Domain\Schema;

use RuntimeException;

/** A command precondition failed (mirrors CommandError in packages/app-schema). */
class CommandException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly ?int $commandIndex = null)
    {
        parent::__construct($message);
    }

    public function atIndex(int $index): self
    {
        return new self($this->errorCode, $this->getMessage(), $index);
    }
}
