<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class IntegrationSyncJob extends Model
{
    use HasUlids;

    protected $fillable = ['project_id', 'sync_id', 'kind', 'trigger', 'status', 'attempts', 'stats', 'errors', 'error', 'requested_by', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['stats' => 'array', 'errors' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
