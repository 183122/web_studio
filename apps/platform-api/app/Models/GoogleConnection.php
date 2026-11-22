<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** OAuth tokens are encrypted at rest and never serialized. */
class GoogleConnection extends Model
{
    use HasUlids;

    protected $fillable = ['workspace_id', 'connected_by', 'google_sub', 'email', 'scopes', 'access_token', 'refresh_token', 'expires_at', 'status', 'last_error', 'last_used_at'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'access_token' => 'encrypted', 'refresh_token' => 'encrypted', 'expires_at' => 'datetime', 'last_used_at' => 'datetime'];
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }
}
