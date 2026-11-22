<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Schema\DocumentCodec;
use App\Http\Controllers\Controller;
use App\Http\Resources\TemplateResource;
use App\Models\Template;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TemplateController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return TemplateResource::collection(
            Template::query()->where('is_published', true)->with('currentVersion:id,template_id,version')->orderBy('sort_order')->get(),
        );
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
