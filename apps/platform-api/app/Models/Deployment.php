<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Deployment extends Model
{
    use HasUlids;

    protected $fillable = ['project_id', 'release_id', 'previous_release_id', 'action', 'status', 'source_revision', 'steps', 'error_code', 'error_message', 'created_by', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['steps' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function log(string $step, string $status, string $message = ''): void
    {
        $steps = $this->steps ?? [];
        $steps[] = ['step' => $step, 'status' => $status, 'message' => mb_substr($message, 0, 300), 'at' => now()->toIso8601String()];
        $this->steps = $steps;
        $this->save();
    }
}
