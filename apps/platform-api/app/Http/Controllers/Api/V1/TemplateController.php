<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Schema\DocumentCodec;
use App\Http\Controllers\Controller;
use App\Http\Resources\TemplateResource;
use App\Models\Template;
use App\Support\Like;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TemplateController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = Template::query()->where('is_published', true)->with('currentVersion:id,template_id,version');
        if (in_array($request->query('source'), ['builtin', 'community'], true)) {
            $q->where('source', $request->query('source'));
        }
        if ($category = $request->query('category')) {
            $q->where('category', (string) $category);
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $q->where(function ($w) use ($search) {
                Like::contains($w, 'name', $search);
                Like::contains($w, 'description', $search, 'or');
            });
        }

        return TemplateResource::collection($q->orderBy('sort_order')->orderByDesc('install_count')->limit(100)->get());
    }

    /** Includes the document so the studio can render a live preview. */
    public function show(string $slug): JsonResponse
    {
        $template = Template::query()->where('slug', $slug)->where('is_published', true)->with('currentVersion')->firstOrFail();

        return response()->json(['data' => (new TemplateResource($template))->resolve() + [
            'document' => $template->currentVersion ? DocumentCodec::decodeForResponse($template->currentVersion->document) : null,
        ]]);
    }
}
