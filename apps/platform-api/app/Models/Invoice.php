<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasUlids;

    protected $fillable = ['organization_id', 'subscription_id', 'plan_id', 'number', 'kind', 'status', 'currency', 'subtotal_minor', 'vat_rate_bp', 'vat_minor', 'total_minor', 'lines', 'buyer', 'period_start', 'period_end', 'provider', 'provider_ref', 'paid_at', 'created_by'];

    protected function casts(): array
    {
        return ['lines' => 'array', 'buyer' => 'array', 'period_start' => 'datetime', 'period_end' => 'datetime', 'paid_at' => 'datetime'];
    }
}
