<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Domain\Data\DataContext;
use App\Domain\Integrations\Google\GoogleClient;
use App\Domain\Integrations\Google\SheetsApi;
use App\Domain\Integrations\Sheets\SheetSyncRunner;
use App\Domain\Integrations\Sheets\SheetSyncService;
use App\Http\Controllers\Controller;
use App\Models\IntegrationSyncJob;
use App\Models\Project;
use App\Models\SheetSync;
use App\Services\AuditLogger;
use App\Support\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** M23: Google Sheets mappings, dry runs, runs and job history for a project. */
class SheetSyncController extends Controller
{
    public function __construct(
        private readonly GoogleClient $google,
        private readonly SheetsApi $sheets,
        private readonly SheetSyncService $service,
        private readonly SheetSyncRunner $runner,
        private readonly AuditLogger $audit,
    ) {}

    public static function presentJob(IntegrationSyncJob $j): array
    {
        return [
            'id' => $j->id, 'syncId' => $j->sync_id, 'kind' => $j->kind, 'trigger' => $j->trigger, 'status' => $j->status, 'attempts' => $j->attempts,
            'stats' => $j->stats, 'errors' => $j->errors ?? [], 'error' => $j->error,
            'createdAt' => $j->created_at?->toIso8601String(), 'finishedAt' => $j->finished_at?->toIso8601String(),
        ];
    }

    public static function present(SheetSync $s): array
    {
        $last = IntegrationSyncJob::query()->where('sync_id', $s->id)->latest('created_at')->latest('id')->first();

        return [
            'id' => $s->id, 'modelId' => $s->model_id, 'environment' => $s->environment, 'spreadsheetId' => $s->spreadsheet_id,
            'spreadsheetTitle' => $s->spreadsheet_title, 'sheetTitle' => $s->sheet_title,
            'spreadsheetUrl' => 'https://docs.google.com/spreadsheets/d/'.$s->spreadsheet_id,
            'mapping' => $s->mapping, 'keyFieldId' => $s->key_field_id, 'direction' => $s->direction, 'conflictPolicy' => $s->conflict_policy,
            'intervalMinutes' => $s->interval_minutes, 'status' => $s->status, 'lastSyncedAt' => $s->last_synced_at?->toIso8601String(),
            'lastJob' => $last ? self::presentJob($last) : null,
        ];
    }

    private function find(Project $project, string $id): SheetSync
    {
        return SheetSync::query()->where('project_id', $project->id)->whereKey($id)->firstOr(fn () => throw new ApiException(404, 'not_found', 'ไม่พบการซิงก์'));
    }

    public function inspect(Request $request, Project $project): JsonResponse
    {
        $this->authorize('manage', $project);
        $data = $request->validate(['url' => ['required', 'string', 'max:500']]);
        $id = SheetsApi::parseId($data['url']) ?? throw ApiException::unprocessable('invalid_sheet_url', 'ลิงก์ Google Sheets ไม่ถูกต้อง');
        $info = $this->sheets->spreadsheet($this->google->connectionFor($project->workspace_id, 'sheets'), $id);

        return response()->json(['data' => ['spreadsheetId' => $id] + $info]);
    }

    public function preview(Request $request, Project $project): JsonResponse
    {
        $this->authorize('manage', $project);
        $data = $request->validate(['spreadsheetId' => ['required', 'string', 'max:128'], 'sheetTitle' => ['required', 'string', 'max:100']]);
        $values = $this->sheets->values($this->google->connectionFor($project->workspace_id, 'sheets'), $data['spreadsheetId'], $data['sheetTitle']);

        return response()->json(['data' => [
            'header' => array_map(fn ($h) => trim((string) $h), $values[0] ?? []),
            'rows' => array_slice($values, 1, 5),
            'totalRows' => max(0, count($values) - 1),
        ]]);
    }

    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json(['data' => SheetSync::query()->where('project_id', $project->id)->orderBy('created_at')->get()->map(fn ($s) => self::present($s))]);
    }

    private function rules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';

        return [
            'modelId' => [$req, 'string', 'max:64'],
            'environment' => [$req, Rule::in(['preview', 'production'])],
            'spreadsheetId' => [$req, 'string', 'max:128', 'regex:/^[a-zA-Z0-9_-]{20,128}$/'],
            'spreadsheetTitle' => ['nullable', 'string', 'max:200'],
            'sheetTitle' => [$req, 'string', 'max:100'],
            'mapping' => [$req, 'array', 'min:1', 'max:50'],
            'mapping.*.fieldId' => ['required', 'string', 'max:64'],
            'mapping.*.column' => ['required', 'string', 'max:100'],
            'keyFieldId' => ['nullable', 'string', 'max:64'],
            'direction' => [$req, Rule::in(['pull', 'push', 'both'])],
            'conflictPolicy' => ['sometimes', Rule::in(['app_wins', 'sheet_wins', 'skip'])],
            'intervalMinutes' => ['sometimes', 'integer', Rule::in([0, 15, 60, 360, 1440])],
            'status' => ['sometimes', Rule::in(['active', 'paused'])],
        ];
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('manage', $project);
        $d = $request->validate($this->rules(true));
        $model = DataContext::forEnvironment($project, $d['environment'])->model($d['modelId']);
        $mapping = array_map(fn ($m) => ['fieldId' => $m['fieldId'], 'column' => trim($m['column'])], $d['mapping']);
        SheetSyncService::assertMapping($model, $mapping, $d['keyFieldId'] ?? null, $d['direction']);
        if (SheetSync::query()->where('project_id', $project->id)->count() >= 20) {
            throw ApiException::unprocessable('too_many_syncs', 'สร้างการซิงก์ได้สูงสุด 20 รายการต่อโปรเจกต์');
        }
        $sync = SheetSync::create([
            'project_id' => $project->id, 'environment' => $d['environment'], 'model_id' => $model['id'], 'spreadsheet_id' => $d['spreadsheetId'],
            'spreadsheet_title' => $d['spreadsheetTitle'] ?? null, 'sheet_title' => $d['sheetTitle'], 'mapping' => $mapping,
            'key_field_id' => $d['keyFieldId'] ?? null, 'direction' => $d['direction'], 'conflict_policy' => $d['conflictPolicy'] ?? 'app_wins',
            'interval_minutes' => $d['intervalMinutes'] ?? 0, 'next_run_at' => ($d['intervalMinutes'] ?? 0) > 0 ? now()->addMinutes($d['intervalMinutes']) : null,
            'created_by' => $request->user()->id,
        ]);
        $this->audit->record('sheets.sync_created', $project, ['sync_id' => $sync->id, 'model' => $model['name'], 'direction' => $sync->direction, 'spreadsheet' => $sync->spreadsheet_id]);

        return response()->json(['data' => self::present($sync)], 201);
    }

    public function update(Request $request, Project $project, string $id): JsonResponse
    {
        $this->authorize('manage', $project);
        $sync = $this->find($project, $id);
        $d = $request->validate(array_intersect_key($this->rules(false), array_flip(['conflictPolicy', 'intervalMinutes', 'status'])));
        if (($d['status'] ?? null) === 'active' && $sync->status === 'disconnected') {
            $this->google->connectionFor($project->workspace_id, 'sheets'); // must be connected again
        }
        $sync->fill(array_filter([
            'conflict_policy' => $d['conflictPolicy'] ?? null, 'status' => $d['status'] ?? null,
        ], fn ($v) => $v !== null));
        if (array_key_exists('intervalMinutes', $d)) {
            $sync->interval_minutes = $d['intervalMinutes'];
            $sync->next_run_at = $d['intervalMinutes'] > 0 ? now()->addMinutes($d['intervalMinutes']) : null;
        }
        $sync->save();

        return response()->json(['data' => self::present($sync)]);
    }

    public function destroy(Project $project, string $id): JsonResponse
    {
        $this->authorize('manage', $project);
        $sync = $this->find($project, $id);
        $sync->delete();
        $this->audit->record('sheets.sync_deleted', $project, ['sync_id' => $id]);

        return response()->json(['data' => ['ok' => true]]);
    }

    public function dryRun(Project $project, string $id): JsonResponse
    {
        $this->authorize('manage', $project);
        $sync = $this->find($project, $id);
        if ($sync->direction === 'push') {
            throw ApiException::unprocessable('not_applicable', 'การซิงก์แบบส่งออกอย่างเดียวไม่มีการนำเข้าให้ทดลอง');
        }

        return response()->json(['data' => $this->service->pull($sync, true)]);
    }

    public function run(Request $request, Project $project, string $id): JsonResponse
    {
        $this->authorize('manage', $project);
        $sync = $this->find($project, $id);
        if ($sync->status !== 'active') {
            throw ApiException::conflict('sync_not_active', $sync->status === 'disconnected' ? 'การเชื่อมต่อ Google ถูกยกเลิก กรุณาเชื่อมต่อใหม่แล้วเปิดใช้งานการซิงก์' : 'การซิงก์ถูกหยุดชั่วคราว');
        }
        if (IntegrationSyncJob::query()->where('sync_id', $sync->id)->whereIn('status', ['queued', 'running', 'retrying'])->exists()) {
            throw ApiException::conflict('sync_in_progress', 'กำลังซิงก์อยู่');
        }
        $job = $this->runner->enqueue($sync, 'manual', $request->user());
        $this->audit->record('sheets.sync_run', $project, ['sync_id' => $sync->id, 'job_id' => $job->id]);

        return response()->json(['data' => self::presentJob($job->fresh())], 202);
    }

    public function jobs(Project $project, string $id): JsonResponse
    {
        $this->authorize('view', $project);
        $sync = $this->find($project, $id);

        return response()->json(['data' => IntegrationSyncJob::query()->where('sync_id', $sync->id)->latest('created_at')->latest('id')->limit(30)->get()->map(fn ($j) => self::presentJob($j))]);
    }

    /** Re-runs a failed or dead-lettered job as a new job. */
    public function retry(Request $request, Project $project, string $jobId): JsonResponse
    {
        $this->authorize('manage', $project);
        $job = IntegrationSyncJob::query()->where('project_id', $project->id)->whereKey($jobId)->firstOr(fn () => throw new ApiException(404, 'not_found', 'ไม่พบงาน'));
        if (! in_array($job->status, ['failed', 'dead', 'partial'], true)) {
            throw ApiException::conflict('not_retryable', 'งานนี้ไม่อยู่ในสถานะที่ลองใหม่ได้');
        }
        $sync = $this->find($project, (string) $job->sync_id);
        if ($sync->status !== 'active') {
            throw ApiException::conflict('sync_not_active', 'การซิงก์ไม่ได้เปิดใช้งาน');
        }

        return response()->json(['data' => self::presentJob($this->runner->enqueue($sync, 'retry', $request->user(), $job->kind)->fresh())], 202);
    }
}
