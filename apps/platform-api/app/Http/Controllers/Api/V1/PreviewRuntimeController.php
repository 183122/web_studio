<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Data\Actor;
use App\Domain\Data\DataContext;
use App\Domain\Data\RecordService;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Support\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Runtime data API used by the private preview. The studio member chooses which app role to
 * simulate (X-Preview-Role); the generated app's permission matrix is enforced exactly as it will
 * be in the published runtime. Data lives in the separate "preview" environment.
 */
class PreviewRuntimeController extends Controller
{
    public function __construct(private readonly RecordService $records) {}

    private function setup(Request $request, Project $project): array
    {
        $this->authorize('view', $project);
        $role = (string) $request->header('X-Preview-Role', 'public');
        if (! in_array($role, ['public', 'member', 'admin'], true)) {
            throw ApiException::unprocessable('invalid_role', 'X-Preview-Role must be public, member or admin');
        }

        return [DataContext::forProject($project), Actor::previewRole($role, $request->user()->id, $request->ip())];
    }

    public function index(Request $request, Project $project, string $modelId): JsonResponse
    {
        [$ctx, $actor] = $this->setup($request, $project);
        $model = $ctx->model($modelId);
        $q = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'string', 'max:64'],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $page = $this->records->list($ctx, $model, $actor, $q);

        return response()->json([
            'data' => array_map(fn ($r) => RecordService::present($r, $model), $page->items()),
            'meta' => ['currentPage' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** Form submission. Spam controls: honeypot field, minimum fill time, rate limit (route). */
    public function store(Request $request, Project $project, string $modelId): JsonResponse
    {
        [$ctx, $actor] = $this->setup($request, $project);
        $model = $ctx->model($modelId);
        $body = $request->validate([
            'data' => ['required', 'array', 'max:100'],
            'meta' => ['required', 'array'],
            'meta.hp' => ['present', 'nullable', 'string', 'max:0'],
            'meta.elapsedMs' => ['required', 'integer', 'min:1500'],
        ], [
            'meta.hp.max' => 'spam detected',
            'meta.elapsedMs.min' => 'submitted too quickly',
        ]);
        $record = $this->records->create($ctx, $model, $body['data'], $actor, 'form');

        return response()->json(['data' => ['id' => $record->id]], 201);
    }
}
