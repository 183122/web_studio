<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\AppRevision */
class RevisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'revision' => $this->revision,
            'source' => $this->source,
            'message' => $this->message,
            'contentHash' => $this->content_hash,
            'sizeBytes' => $this->size_bytes,
            'restoredFromRevision' => $this->restored_from_revision,
            'author' => $this->whenLoaded('author', fn () => $this->author ? ['id' => $this->author->id, 'name' => $this->author->name] : null),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
