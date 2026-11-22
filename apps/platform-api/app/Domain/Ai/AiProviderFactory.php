<?php

namespace App\Domain\Ai;

use App\Domain\Ai\Providers\AiProvider;
use App\Domain\Ai\Providers\AnthropicProvider;
use App\Domain\Ai\Providers\LocalRulesProvider;
use App\Models\AiCredential;
use App\Models\Organization;
use Psr\Http\Client\ClientInterface;

/**
 * Chooses the provider for an organisation: BYOK credential → platform key → offline rules.
 * `transporter` lets tests drive the real SDK through a fake PSR-18 client (no network).
 */
class AiProviderFactory
{
    public ?ClientInterface $transporter = null;

    public function forOrganization(Organization $org): AiProvider
    {
        $cfg = config('studio.ai');
        $byok = AiCredential::query()->where('organization_id', $org->id)->where('provider', 'anthropic')->first();
        $key = $byok?->secret ?: ($cfg['anthropic_api_key'] ?? null);
        if (is_string($key) && $key !== '') {
            return new AnthropicProvider($key, $cfg['model'], $cfg, $this->transporter);
        }

        return new LocalRulesProvider;
    }

    public function fallback(): AiProvider
    {
        return new LocalRulesProvider;
    }

    public function keySource(Organization $org): string
    {
        if (AiCredential::query()->where('organization_id', $org->id)->where('provider', 'anthropic')->exists()) {
            return 'byok';
        }

        return config('studio.ai.anthropic_api_key') ? 'platform' : 'none';
    }
}
