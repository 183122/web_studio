<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A hostname bound to a project. `active_hostname` is unique and equals `hostname` until the
 * domain is released, so a hostname can be bound to only one project at a time.
 */
class Domain extends Model
{
    use HasUlids;

    protected $fillable = ['organization_id', 'project_id', 'hostname', 'type', 'status', 'verification_token', 'checks', 'verified_at', 'tls_active_at', 'last_checked_at', 'released_at', 'active_hostname'];

    protected $hidden = [];

    protected function casts(): array
    {
        return ['checks' => 'array', 'verified_at' => 'datetime', 'tls_active_at' => 'datetime', 'last_checked_at' => 'datetime', 'released_at' => 'datetime'];
    }

    public function isServing(): bool
    {
        return $this->released_at === null && ($this->type === 'subdomain' ? $this->status === 'active' : $this->status === 'active');
    }
}
