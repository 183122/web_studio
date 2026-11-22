<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Immutable published snapshot. `document` is canonical JSON text; never updated after insert. */
class Release extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = ['project_id', 'version', 'source_revision', 'document', 'manifest', 'content_hash', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['manifest' => 'array', 'version' => 'integer', 'source_revision' => 'integer'];
    }
}
