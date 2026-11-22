<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WebhookEndpoint extends Model
{
    use HasUlids;

    protected $fillable = ['project_id', 'environment', 'url', 'events', 'secret', 'description', 'enabled', 'consecutive_failures', 'disabled_reason', 'created_by'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['events' => 'array', 'secret' => 'encrypted', 'enabled' => 'boolean'];
    }
}
