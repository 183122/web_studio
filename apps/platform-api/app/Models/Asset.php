<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Asset extends Model
{
    use HasUlids, SoftDeletes;

    /** Statuses for which the file may be served. */
    public const SERVABLE_STATUSES = ['clean', 'not_scanned'];

    protected $fillable = [
        'organization_id', 'workspace_id', 'uploaded_by', 'disk', 'path', 'original_name',
        'mime', 'size_bytes', 'sha256', 'width', 'height', 'scan_status',
    ];

    protected $hidden = ['disk', 'path'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'width' => 'integer', 'height' => 'integer'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function isServable(): bool
    {
        return in_array($this->scan_status, self::SERVABLE_STATUSES, true);
    }
}
