<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasUlids;

    protected $fillable = ['invoice_id', 'provider', 'provider_ref', 'amount_minor', 'currency', 'status', 'failure_reason'];
}
