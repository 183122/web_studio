<?php

namespace App\Http\Resources;

use App\Services\AssetService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Asset */
class AssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ref' => 'asset:'.$this->id,
            'name' => $this->original_name,
            'mime' => $this->mime,
            'sizeBytes' => $this->size_bytes,
            'width' => $this->width,
            'height' => $this->height,
            'scanStatus' => $this->scan_status,
            'url' => $this->isServable() ? app(AssetService::class)->signedUrl($this->resource) : null,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
