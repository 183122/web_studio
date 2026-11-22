<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only command log entry. */
class AppChangeEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['project_id', 'revision', 'seq', 'command_type', 'payload', 'created_by'];
}
