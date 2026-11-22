<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use HasUlids;

    protected $fillable = ['organization_id', 'plan_id', 'status', 'provider', 'payment_method_ref', 'current_period_start', 'current_period_end', 'cancel_at_period_end', 'dunning_attempts', 'next_retry_at', 'ended_at'];

    protected $hidden = ['payment_method_ref'];

    protected function casts(): array
    {
        return ['current_period_start' => 'datetime', 'current_period_end' => 'datetime', 'next_retry_at' => 'datetime', 'ended_at' => 'datetime', 'cancel_at_period_end' => 'boolean'];
    }
}
