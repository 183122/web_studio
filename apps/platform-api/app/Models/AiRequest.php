<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class AiRequest extends Model
{
    use HasUlids;

    protected $fillable = [
        'organization_id', 'project_id', 'user_id', 'kind', 'status', 'provider', 'model', 'fallback_used',
        'consent_external', 'prompt_redacted', 'prompt_hash', 'context', 'result', 'input_tokens', 'output_tokens',
        'cost_micro_usd', 'credits_reserved', 'credits_charged', 'latency_ms', 'error_code', 'error_message',
    ];

    protected function casts(): array
    {
        return ['context' => 'array', 'result' => 'array', 'fallback_used' => 'boolean', 'consent_external' => 'boolean'];
    }
}
