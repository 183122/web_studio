<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Immutable checkpoint. Never updated after insert. */
class AppRevision extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = ['project_id', 'revision', 'document', 'content_hash', 'size_bytes', 'source', 'message', 'restored_from_revision', 'created_by'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'size_bytes' => 'integer', 'restored_from_revision' => 'integer'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
