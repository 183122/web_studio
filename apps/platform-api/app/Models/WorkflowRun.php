<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowRun extends Model
{
    protected $fillable = ['project_id', 'environment', 'workflow_id', 'record_id', 'event', 'event_key', 'status', 'steps', 'error'];

    protected function casts(): array
    {
        return ['steps' => 'array'];
    }
}
