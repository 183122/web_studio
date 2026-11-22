<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Workspace extends Model
{
    use HasUlids, SoftDeletes;

    public const ROLES = ['admin', 'editor', 'developer', 'viewer'];

    /** Roles allowed to change project content. */
    public const EDIT_ROLES = ['admin', 'editor', 'developer'];

    protected $fillable = ['organization_id', 'name', 'slug'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_members')->withPivot('role')->withTimestamps();
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }
}
