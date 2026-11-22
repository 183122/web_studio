<?php

namespace App\Domain\Ai\Providers;

use App\Domain\Ai\AiCall;
use App\Domain\Ai\AiCompletion;
use App\Domain\Ai\AiProviderException;

interface AiProvider
{
    public function name(): string;

    public function model(): string;

    /** Whether the provider sends content to a third party (requires user consent). */
    public function isExternal(): bool;

    /** @throws AiProviderException */
    public function complete(AiCall $call): AiCompletion;
}
