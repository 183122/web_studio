<?php

namespace App\Http\Resources;

use App\Models\Template;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Template */
class TemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'version' => $this->currentVersion?->version,
            'source' => $this->source,
            'license' => $this->license,
            'authorName' => $this->source === 'community' ? $this->author_name : null,
            'installCount' => $this->install_count,
        ];
    }
}
