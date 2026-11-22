<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Head (latest) document of a project. `document` holds canonical JSON text;
 * use App\Domain\Schema\DocumentCodec to decode/encode.
 */
class AppDefinition extends Model
{
    protected $fillable = ['project_id', 'current_revision', 'schema_version', 'document', 'content_hash', 'size_bytes', 'updated_by'];

    protected function casts(): array
    {
        return ['current_revision' => 'integer', 'schema_version' => 'integer', 'size_bytes' => 'integer'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
