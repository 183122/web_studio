<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUlids, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'locale'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_platform_admin' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'consented_at' => 'datetime',
            'marketing_consent_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_members')->withPivot('role')->withTimestamps();
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_members')->withPivot('role')->withTimestamps();
    }

    /** Workspace role for this user, or null when not a member (tenant boundary). */
    public function workspaceRole(string $workspaceId): ?string
    {
        $row = WorkspaceMember::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $this->id)
            ->first();

        if ($row !== null) {
            return $row->role;
        }

        // Approved, unexpired support access → read-only view of that organization's workspaces.
        if ($this->is_platform_admin) {
            $orgId = Workspace::query()->whereKey($workspaceId)->value('organization_id');
            if ($orgId && SupportGrant::activeFor($this->id, $orgId)) {
                return 'viewer';
            }
        }

        return null;
    }
}
