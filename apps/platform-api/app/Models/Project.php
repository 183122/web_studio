<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use HasUlids, SoftDeletes;

    public const STATUSES = ['active', 'archived'];

    protected $fillable = ['organization_id', 'workspace_id', 'name', 'slug', 'description', 'status', 'template_id', 'created_by'];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function definition(): HasOne
    {
        return $this->hasOne(AppDefinition::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(AppRevision::class);
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(ProjectFavorite::class);
    }
}
