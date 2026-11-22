<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** End user of a generated app (separate from platform users; scoped to one project + environment). */
class AppUser extends Model
{
    use HasUlids;

    protected $fillable = ['project_id', 'environment', 'email', 'name', 'password', 'role', 'status', 'last_login_at'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'last_login_at' => 'datetime'];
    }
}
