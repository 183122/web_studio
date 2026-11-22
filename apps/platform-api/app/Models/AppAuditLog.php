<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['project_id', 'environment', 'model_id', 'record_id', 'action', 'actor_type', 'actor_id', 'changes', 'ip_address'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }
}
