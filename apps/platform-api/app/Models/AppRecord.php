<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A generated-app record. `data` is stored as JSON text keyed by field id (unicode unescaped so
 * search works for Thai); read it through `values()`.
 */
class AppRecord extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = ['project_id', 'environment', 'model_id', 'data', 'version', 'source', 'created_by'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    /** @return array<string, mixed> */
    public function values(): array
    {
        $raw = $this->getAttribute('data');
        $data = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($data) ? $data : [];
    }

    public function setValues(array $values): void
    {
        $this->setAttribute('data', json_encode((object) $values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }
}
