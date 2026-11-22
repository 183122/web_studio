<?php

namespace App\Domain\Ai;

/** One structured-output request to a provider. */
final class AiCall
{
    public function __construct(
        public readonly string $purpose,   // plan | patch | repair
        public readonly string $system,
        public readonly string $user,
        public readonly array $schema,
        public readonly int $maxTokens,
        /** Original (unredacted) prompt + context for the offline provider. */
        public readonly string $prompt = '',
        public readonly array $context = [],
    ) {}
}
