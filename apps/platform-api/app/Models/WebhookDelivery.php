<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WebhookDelivery extends Model
{
    use HasUlids;

    protected $fillable = ['endpoint_id', 'event_id', 'event', 'payload', 'status', 'attempts', 'response_status', 'error', 'delivered_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'delivered_at' => 'datetime'];
    }
}
