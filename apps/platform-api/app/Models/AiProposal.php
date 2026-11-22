<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class AiProposal extends Model
{
    use HasUlids;

    protected $fillable = ['project_id', 'ai_request_id', 'created_by', 'status', 'base_revision', 'applied_revision', 'commands', 'summary', 'expires_at'];

    protected function casts(): array
    {
        return ['summary' => 'array', 'expires_at' => 'datetime'];
    }
}
