<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class AppApiKey extends Model
{
    use HasUlids;

    public const SCOPES = ['records:read', 'records:write'];

    protected $fillable = ['project_id', 'environment', 'name', 'prefix', 'key_hash', 'role', 'scopes', 'created_by', 'last_used_at', 'revoked_at'];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
