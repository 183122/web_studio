<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssetResource;
use App\Models\Asset;
use App\Models\Workspace;
use App\Services\AssetService;
use App\Support\Like;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class AssetController extends Controller
{
    public function __construct(private readonly AssetService $assets) {}

    public function index(Request $request, Workspace $workspace): AnonymousResourceCollection
    {
        $this->authorize('view', $workspace);
        $data = $request->validate([
            'ids' => ['nullable', 'array', 'max:200'],
            'ids.*' => ['string', 'size:26'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $query = Asset::query()->where('workspace_id', $workspace->id)->orderByDesc('created_at');
        if (! empty($data['ids'])) {
            $query->whereIn('id', $data['ids']);

            return AssetResource::collection($query->get());
        }
        if (! empty($data['search'])) {
            Like::contains($query, 'original_name', $data['search']);
        }

        return AssetResource::collection($query->paginate(48));
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('edit', $workspace);
        $maxKb = intdiv((int) config('studio.assets.max_upload_bytes'), 1024);
        $request->validate(['file' => ['required', 'file', "max:{$maxKb}"]]);
        $result = $this->assets->upload($workspace, $request->user(), $request->file('file'));

        return (new AssetResource($result['asset']))
            ->additional(['meta' => ['deduplicated' => $result['deduplicated']]])
            ->response()
            ->setStatusCode($result['deduplicated'] ? 200 : 201);
    }

    public function destroy(Asset $asset): JsonResponse
    {
        $this->authorize('delete', $asset);
        $this->assets->delete($asset);

        return response()->json(['data' => ['ok' => true]]);
    }

    /** Served only through a short-lived signed URL (route middleware: signed:relative). */
    public function content(Asset $asset): Response
    {
        abort_unless($asset->isServable(), 404);
        $disk = Storage::disk($asset->disk);
        abort_unless($disk->exists($asset->path), 404);

        return response($disk->get($asset->path), 200, [
            'Content-Type' => $asset->mime,
            'Content-Length' => (string) $asset->size_bytes,
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, max-age=600',
        ]);
    }
}
