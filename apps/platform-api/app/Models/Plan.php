<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasUlids;

    protected $fillable = ['code', 'name', 'entitlements', 'price_minor', 'currency', 'is_active', 'trial_days', 'sort'];

    protected function casts(): array
    {
        return ['entitlements' => 'array', 'is_active' => 'boolean'];
    }
}
