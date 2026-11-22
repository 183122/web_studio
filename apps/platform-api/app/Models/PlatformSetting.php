<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Platform-wide setting; `value` is an encrypted JSON object and never serialized. */
class PlatformSetting extends Model
{
    protected $fillable = ['key', 'value', 'updated_by'];

    protected $hidden = ['value'];

    protected function casts(): array
    {
        return ['value' => 'encrypted:array'];
    }
}
