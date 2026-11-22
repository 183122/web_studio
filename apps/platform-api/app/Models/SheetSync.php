<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class SheetSync extends Model
{
    use HasUlids;

    protected $fillable = ['project_id', 'environment', 'model_id', 'spreadsheet_id', 'spreadsheet_title', 'sheet_title', 'mapping', 'key_field_id', 'direction', 'conflict_policy', 'interval_minutes', 'status', 'last_synced_at', 'next_run_at', 'created_by'];

    protected function casts(): array
    {
        return ['mapping' => 'array', 'last_synced_at' => 'datetime', 'next_run_at' => 'datetime', 'interval_minutes' => 'integer'];
    }
}
