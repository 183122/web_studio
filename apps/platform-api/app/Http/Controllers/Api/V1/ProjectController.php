<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\ProjectFavorite;
use App\Models\Template;
use App\Models\Workspace;
use App\Services\AuditLogger;
use App\Services\ProjectService;
use App\Support\Like;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function __construct(private readonly ProjectService $projects, private readonly AuditLogger $audit) {}

    public function index(Request $request, Workspace $workspace): AnonymousResourceCollection
    {
        $this->authorize('view', $workspace);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'archived', 'trashed', 'all'])],
            'favorites' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['updated', 'name', 'created'])],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);
        $userId = $request->user()->id;

        $query = Project::query()
            ->where('workspace_id', $workspace->id)
            ->with(['definition:id,project_id,current_revision', 'template:id,slug,name'])
            ->withExists(['favorites as is_favorite' => fn ($q) => $q->where('user_id', $userId)]);

        match ($filters['status'] ?? 'active') {
            'trashed' => $query->onlyTrashed(),
            'all' => $query,
            default => $query->where('status', $filters['status'] ?? 'active'),
        };
        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(fn ($q) => Like::contains(Like::contains($q, 'name', $term), 'description', $term, 'or'));
        }
        if ($request->boolean('favorites')) {
            $query->whereHas('favorites', fn ($q) => $q->where('user_id', $userId));
        }
        match ($filters['sort'] ?? 'updated') {
            'name' => $query->orderBy('name'),
            'created' => $query->orderByDesc('created_at'),
            default => $query->orderByDesc('updated_at'),
        };

        return ProjectResource::collection($query->paginate($filters['perPage'] ?? 24)->withQueryString());
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('edit', $workspace);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'templateSlug' => ['nullable', 'string', 'max:80'],
        ]);
        $template = null;
        if (! empty($data['templateSlug'])) {
            $template = Template::query()->where('slug', $data['templateSlug'])->where('is_published', true)->with('currentVersion')->first();
            abort_if($template === null, 422, 'ไม่พบเทมเพลต');
        }
        $project = $this->projects->create($workspace, $request->user(), trim($data['name']), $data['description'] ?? '', $template);

        return (new ProjectResource($project->load(['definition', 'template'])))->response()->setStatusCode(201);
    }

    public function show(Request $request, Project $project): ProjectResource
    {
        $this->authorize('view', $project);
        $project->load(['definition:id,project_id,current_revision', 'template:id,slug,name']);
        $project->is_favorite = ProjectFavorite::query()->where('project_id', $project->id)->where('user_id', $request->user()->id)->exists();
        $project->viewer_role = $request->user()->workspaceRole($project->workspace_id);

        return new ProjectResource($project);
    }

    public function update(Request $request, Project $project): ProjectResource
    {
        $this->authorize('manage', $project);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        if (array_key_exists('description', $data)) {
            $data['description'] = $data['description'] ?? '';
        }
        $project->update($data);
        $this->audit->record('project.updated', $project, ['fields' => array_keys($data)]);

        return new ProjectResource($project);
    }

    public function archive(Project $project): ProjectResource
    {
        $this->authorize('manage', $project);
        $project->update(['status' => 'archived']);
        $this->audit->record('project.archived', $project);

        return new ProjectResource($project);
    }

    public function unarchive(Project $project): ProjectResource
    {
        $this->authorize('manage', $project);
        $project->update(['status' => 'active']);
        $this->audit->record('project.unarchived', $project);

        return new ProjectResource($project);
    }

    public function duplicate(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        $this->authorize('edit', $project->workspace);
        $data = $request->validate(['name' => ['nullable', 'string', 'max:120']]);
        $copy = $this->projects->duplicate($project, $request->user(), $data['name'] ?? null);

        return (new ProjectResource($copy->load(['definition', 'template'])))->response()->setStatusCode(201);
    }

    /** Soft delete (moves to trash; recoverable). */
    public function destroy(Project $project): JsonResponse
    {
        $this->authorize('delete', $project);
        $project->delete();
        $this->audit->record('project.trashed', $project);

        return response()->json(['data' => ['ok' => true]]);
    }

    public function restore(Project $project): ProjectResource
    {
        $this->authorize('delete', $project);
        abort_unless($project->trashed(), 409, 'โปรเจกต์นี้ไม่ได้อยู่ในถังขยะ');
        $this->projects->restoreFromTrash($project);

        return new ProjectResource($project->fresh());
    }

    public function favorite(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        ProjectFavorite::query()->firstOrCreate(['project_id' => $project->id, 'user_id' => $request->user()->id]);

        return response()->json(['data' => ['isFavorite' => true]]);
    }

    public function unfavorite(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        ProjectFavorite::query()->where('project_id', $project->id)->where('user_id', $request->user()->id)->delete();

        return response()->json(['data' => ['isFavorite' => false]]);
    }
}
