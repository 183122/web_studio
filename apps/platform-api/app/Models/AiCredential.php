<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Bring-your-own-key credential. `secret` is encrypted at rest and hidden from serialization. */
class AiCredential extends Model
{
    protected $fillable = ['organization_id', 'provider', 'secret', 'last4', 'created_by'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted'];
    }
}
