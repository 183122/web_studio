<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PreviewLink extends Model
{
    use HasUlids;

    protected $fillable = ['project_id', 'token_hash', 'revision', 'document', 'expires_at', 'revoked_at', 'created_by'];

    protected $hidden = ['token_hash', 'document'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
