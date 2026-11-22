<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectFavorite extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['project_id', 'user_id'];
}
