<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only. No update/delete paths exist in application code. */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['organization_id', 'workspace_id', 'actor_id', 'action', 'subject_type', 'subject_id', 'ip_address', 'user_agent', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
