<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Data\Actor;
use App\Domain\Data\CsvService;
use App\Domain\Data\DataContext;
use App\Domain\Data\MigrationImpact;
use App\Domain\Data\RecordService;
use App\Domain\Schema\SchemaException;
use App\Domain\Schema\StructuralValidator;
use App\Http\Controllers\Controller;
use App\Models\AppAuditLog;
use App\Models\Project;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Generated admin (M17) for workspace members: CRUD over the project's preview data.
 * Viewers may read; admin/editor/developer may write.
 */
class DataController extends Controller
{
    public function __construct(private readonly RecordService $records) {}

    private function ctx(Project $project, string $ability): DataContext
    {
        $this->authorize($ability, $project);

        return DataContext::forProject($project);
    }

    private function actor(Request $request): Actor
    {
        return Actor::studio($request->user()->id, $request->ip());
    }

    public function index(Request $request, Project $project, string $modelId): JsonResponse
    {
        $ctx = $this->ctx($project, 'view');
        $model = $ctx->model($modelId);
        $q = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'filter' => ['nullable', 'array', 'max:20'],
            'filter.*' => ['nullable', 'string', 'max:200'],
            'sort' => ['nullable', 'string', 'max:64'],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = $this->records->list($ctx, $model, $this->actor($request), [
            'search' => $q['search'] ?? null, 'filters' => $q['filter'] ?? [], 'sort' => $q['sort'] ?? null, 'dir' => $q['dir'] ?? null, 'perPage' => $q['perPage'] ?? 20,
        ]);

        return response()->json([
            'data' => array_map(fn ($r) => RecordService::present($r, $model), $page->items()),
            'meta' => ['currentPage' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'perPage' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Project $project, string $modelId, string $recordId): JsonResponse
    {
        $ctx = $this->ctx($project, 'view');
        $model = $ctx->model($modelId);

        return response()->json(['data' => RecordService::present($this->records->find($ctx, $model, $recordId), $model)]);
    }

    public function store(Request $request, Project $project, string $modelId): JsonResponse
    {
        $ctx = $this->ctx($project, 'manage');
        $model = $ctx->model($modelId);
        $data = $request->validate(['data' => ['required', 'array']]);
        $record = $this->records->create($ctx, $model, $data['data'], $this->actor($request), 'studio');

        return response()->json(['data' => RecordService::present($record->fresh(), $model)], 201);
    }

    public function update(Request $request, Project $project, string $modelId, string $recordId): JsonResponse
    {
        $ctx = $this->ctx($project, 'manage');
        $model = $ctx->model($modelId);
        $data = $request->validate(['data' => ['required', 'array'], 'version' => ['required', 'integer', 'min:1']]);
        $record = $this->records->update($ctx, $model, $this->records->find($ctx, $model, $recordId), $data['data'], (int) $data['version'], $this->actor($request), 'studio');

        return response()->json(['data' => RecordService::present($record->fresh(), $model)]);
    }

    public function destroy(Request $request, Project $project, string $modelId, string $recordId): JsonResponse
    {
        $ctx = $this->ctx($project, 'manage');
        $model = $ctx->model($modelId);
        $this->records->delete($ctx, $model, $this->records->find($ctx, $model, $recordId), $this->actor($request));

        return response()->json(['data' => ['ok' => true]]);
    }

    public function bulkDelete(Request $request, Project $project, string $modelId): JsonResponse
    {
        $ctx = $this->ctx($project, 'manage');
        $model = $ctx->model($modelId);
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['string', 'size:26']]);
        $records = $this->records->scope($ctx, $model)->whereIn('id', $data['ids'])->get();
        foreach ($records as $record) {
            $this->records->delete($ctx, $model, $record, $this->actor($request));
        }
        app(AuditLogger::class)->record('data.bulk_deleted', $project, ['model' => $model['name'], 'count' => $records->count()]);

        return response()->json(['data' => ['deleted' => $records->count()]]);
    }

    public function export(Project $project, string $modelId, CsvService $csv): StreamedResponse
    {
        $ctx = $this->ctx($project, 'view');
        $model = $ctx->model($modelId);
        $stream = $csv->export($ctx, $model);
        app(AuditLogger::class)->record('data.exported', $project, ['model' => $model['name']]);

        return response()->streamDownload(function () use ($stream) {
            fpassthru($stream);
            fclose($stream);
        }, "{$model['name']}-".now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function import(Request $request, Project $project, string $modelId, CsvService $csv): JsonResponse
    {
        $ctx = $this->ctx($project, 'manage');
        $model = $ctx->model($modelId);
        $request->validate(['file' => ['required', 'file', 'max:2048', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel'], 'dryRun' => ['nullable', 'boolean']]);
        $report = $csv->import($ctx, $model, $request->file('file')->getRealPath(), $this->actor($request), $request->boolean('dryRun'));
        if (! $report['dryRun'] && $report['created'] > 0) {
            app(AuditLogger::class)->record('data.imported', $project, ['model' => $model['name'], 'rows' => $report['created']]);
        }

        return response()->json(['data' => $report], $report['errors'] === [] ? 200 : 422);
    }

    /** Migration preview for a proposed model definition (not applied). */
    public function impact(Request $request, Project $project, MigrationImpact $impact): JsonResponse
    {
        $ctx = $this->ctx($project, 'view');
        $body = json_decode($request->getContent(), false, 64);
        $modelObj = $body->model ?? null;
        $cmd = (object) ['type' => 'model.upsert', 'model' => $modelObj, 'index' => 0];
        $issues = StructuralValidator::validateCommand($cmd);
        if ($issues !== []) {
            throw new SchemaException($issues, 'Proposed model is malformed.');
        }
        $proposed = json_decode(json_encode($modelObj), true);
        $current = null;
        foreach ($ctx->doc['dataModels'] as $m) {
            if ($m['id'] === $proposed['id']) {
                $current = $m;
            }
        }
        if ($current === null) {
            return response()->json(['data' => ['totalRecords' => 0, 'requiresConfirmation' => false, 'fields' => []]]);
        }

        return response()->json(['data' => $impact->analyze($ctx, $current, $proposed)]);
    }

    public function audit(Project $project, string $modelId): JsonResponse
    {
        $ctx = $this->ctx($project, 'view');
        $model = $ctx->model($modelId);
        $logs = AppAuditLog::query()->where('project_id', $project->id)->where('model_id', $model['id'])->orderByDesc('id')->paginate(50);

        return response()->json([
            'data' => collect($logs->items())->map(fn (AppAuditLog $l) => [
                'id' => $l->id, 'recordId' => $l->record_id, 'action' => $l->action, 'actorType' => $l->actor_type,
                'changes' => $l->changes, 'createdAt' => $l->created_at?->toIso8601String(),
            ]),
            'meta' => ['currentPage' => $logs->currentPage(), 'lastPage' => $logs->lastPage(), 'total' => $logs->total()],
        ]);
    }
}
