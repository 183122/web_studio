<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class SupportGrant extends Model
{
    use HasUlids;

    protected $fillable = ['organization_id', 'requested_by', 'reason', 'minutes', 'status', 'decided_by', 'decided_at', 'expires_at'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public static function activeFor(string $userId, string $orgId): bool
    {
        return self::query()->where('requested_by', $userId)->where('organization_id', $orgId)->where('status', 'approved')->where('expires_at', '>', now())->exists();
    }
}
