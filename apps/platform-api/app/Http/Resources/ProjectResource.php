<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Project */
class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workspaceId' => $this->workspace_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'status' => $this->status,
            'isTrashed' => $this->deleted_at !== null,
            'isFavorite' => (bool) ($this->is_favorite ?? false),
            'revision' => $this->whenLoaded('definition', fn () => $this->definition?->current_revision),
            'template' => $this->whenLoaded('template', fn () => $this->template ? ['slug' => $this->template->slug, 'name' => $this->template->name] : null),
            'role' => $this->when(isset($this->viewer_role), fn () => $this->viewer_role),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
            'deletedAt' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
