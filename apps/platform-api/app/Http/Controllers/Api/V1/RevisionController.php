<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Schema\DocumentCodec;
use App\Http\Controllers\Controller;
use App\Http\Resources\RevisionResource;
use App\Models\AppRevision;
use App\Models\Project;
use App\Services\AppDefinitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RevisionController extends Controller
{
    public function index(Project $project): AnonymousResourceCollection
    {
        $this->authorize('view', $project);

        return RevisionResource::collection(
            AppRevision::query()
                ->where('project_id', $project->id)
                ->select(['id', 'project_id', 'revision', 'content_hash', 'size_bytes', 'source', 'message', 'restored_from_revision', 'created_by', 'created_at'])
                ->with('author:id,name')
                ->orderByDesc('revision')
                ->paginate(30),
        );
    }

    public function show(Project $project, int $revision): JsonResponse
    {
        $this->authorize('view', $project);
        $rev = AppRevision::query()->where('project_id', $project->id)->where('revision', $revision)->with('author:id,name')->firstOrFail();

        return response()->json(['data' => (new RevisionResource($rev))->resolve() + ['document' => DocumentCodec::decodeForResponse($rev->document)]]);
    }

    public function restore(Request $request, Project $project, int $revision, AppDefinitionService $definitions): JsonResponse
    {
        $this->authorize('update', $project);
        $data = $request->validate(['baseRevision' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => $definitions->restore($project, $revision, (int) $data['baseRevision'], $request->user())]);
    }
}
