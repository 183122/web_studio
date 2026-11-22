<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingProfile extends Model
{
    protected $fillable = ['organization_id', 'legal_name', 'tax_id', 'branch', 'address', 'email'];
}
