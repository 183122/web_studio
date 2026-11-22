<?php

namespace App\Domain\Ai;

use App\Models\AiRequest;
use App\Models\Organization;
use App\Services\EntitlementService;
use App\Support\ApiException;

/**
 * Monthly AI credit metering (1 credit = credit_usd of provider cost, rounded up).
 * Reserve before the call (worst case, under an organisation lock), commit actual usage after.
 */
class AiCredits
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function limit(Organization $org): int
    {
        return $this->entitlements->limit($org, 'ai_credits_monthly');
    }

    public function used(Organization $org): int
    {
        $base = AiRequest::query()->where('organization_id', $org->id)->where('created_at', '>=', now()->startOfMonth());

        return (int) (clone $base)->whereIn('status', ['succeeded', 'failed'])->sum('credits_charged')
            + (int) (clone $base)->whereIn('status', ['queued', 'running'])->sum('credits_reserved');
    }

    public static function priceFor(string $model): array
    {
        $pricing = config('studio.ai.pricing');

        return $pricing[$model] ?? $pricing[config('studio.ai.model')] ?? ['input' => 0, 'output' => 0];
    }

    public static function costMicroUsd(string $model, int $in, int $out): int
    {
        $p = self::priceFor($model);

        return (int) ceil($in * $p['input'] + $out * $p['output']); // $/MTok × tokens = micro-USD
    }

    public static function creditsFor(int $microUsd): int
    {
        return $microUsd <= 0 ? 0 : (int) ceil($microUsd / (config('studio.ai.credit_usd') * 1_000_000));
    }

    /** Worst-case credits for one call: estimated input + full output budget. */
    public static function estimate(string $model, int $inputChars, int $maxTokens): int
    {
        $inTokens = (int) ceil($inputChars / 2) + 3000; // Thai ≈ 2 chars/token; + system prompt + schema

        return self::creditsFor(self::costMicroUsd($model, $inTokens, $maxTokens));
    }

    /** Call inside a transaction holding a lock on the organisation row. */
    public function assertAvailable(Organization $org, int $reserve): void
    {
        $limit = $this->limit($org);
        if ($this->used($org) + $reserve > $limit) {
            throw new ApiException(422, 'ai_credits_exhausted', "เครดิต AI เดือนนี้ไม่พอ (ใช้ไปแล้ว {$this->used($org)}/{$limit})", ['limit' => $limit, 'used' => $this->used($org), 'required' => $reserve]);
        }
    }
}
