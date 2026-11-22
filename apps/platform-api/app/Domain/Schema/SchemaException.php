<?php

namespace App\Domain\Schema;

use RuntimeException;

/** A document or command failed validation. Carries a list of {path, code, message} issues. */
class SchemaException extends RuntimeException
{
    /** @param list<array{path:string,code:string,message:string}> $issues */
    public function __construct(public readonly array $issues, string $message = 'The application definition is invalid.')
    {
        parent::__construct($message);
    }
}
