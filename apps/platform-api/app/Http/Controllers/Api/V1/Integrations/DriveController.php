<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Domain\Data\DataContext;
use App\Domain\Data\RecordService;
use App\Domain\Integrations\Drive\DriveAttachmentService;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\RecordAttachment;
use App\Support\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** M24: project Drive folder and record attachments. */
class DriveController extends Controller
{
    public function __construct(private readonly DriveAttachmentService $drive, private readonly RecordService $records) {}

    private static function presentAttachment(RecordAttachment $a, Project $p): array
    {
        return [
            'id' => $a->id, 'name' => $a->name, 'mime' => $a->mime, 'size' => $a->size, 'status' => $a->status,
            'downloadUrl' => "/api/v1/projects/{$p->id}/attachments/{$a->id}/download",
            'createdAt' => $a->created_at?->toIso8601String(), 'checkedAt' => $a->checked_at?->toIso8601String(),
        ];
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        $folder = $this->drive->folder($project);
        $data = ['folder' => $folder ? ['id' => $folder->folder_id, 'name' => $folder->name] : null];
        if ($request->boolean('withFolders')) {
            $this->authorize('manage', $project);
            $data['folders'] = $this->drive->folders($project);
        }

        return response()->json(['data' => $data]);
    }

    public function setFolder(Request $request, Project $project): JsonResponse
    {
        $this->authorize('manage', $project);
        $d = $request->validate(['folderId' => ['nullable', 'string', 'max:128', 'required_without:newName'], 'newName' => ['nullable', 'string', 'max:120', 'required_without:folderId']]);
        $folder = $this->drive->chooseFolder($project, $d['folderId'] ?? null, isset($d['newName']) ? trim($d['newName']) : null);

        return response()->json(['data' => ['folder' => ['id' => $folder->folder_id, 'name' => $folder->name]]]);
    }

    private function record(Request $request, Project $project, string $modelId, string $recordId): array
    {
        $env = $request->query('env') === 'production' ? 'production' : 'preview';
        $ctx = DataContext::forEnvironment($project, $env);
        $model = $ctx->model($modelId);

        return [$this->records->find($ctx, $model, $recordId, $env), $model];
    }

    public function index(Request $request, Project $project, string $modelId, string $recordId): JsonResponse
    {
        $this->authorize('view', $project);
        [$record] = $this->record($request, $project, $modelId, $recordId);

        return response()->json(['data' => RecordAttachment::query()->where('project_id', $project->id)->where('record_id', $record->id)->orderBy('created_at')->get()
            ->map(fn ($a) => self::presentAttachment($a, $project))]);
    }

    public function store(Request $request, Project $project, string $modelId, string $recordId): JsonResponse
    {
        $this->authorize('manage', $project);
        $request->validate(['file' => ['required', 'file', 'max:10240']]);
        [$record] = $this->record($request, $project, $modelId, $recordId);
        if (RecordAttachment::query()->where('record_id', $record->id)->count() >= 20) {
            throw ApiException::unprocessable('too_many_attachments', 'แนบไฟล์ได้สูงสุด 20 ไฟล์ต่อรายการ');
        }
        $a = $this->drive->upload($project, $record, $request->file('file'), $request->user());

        return response()->json(['data' => self::presentAttachment($a, $project)], 201);
    }

    private function attachment(Project $project, string $id): RecordAttachment
    {
        return RecordAttachment::query()->where('project_id', $project->id)->whereKey($id)->firstOr(fn () => throw new ApiException(404, 'not_found', 'ไม่พบไฟล์แนบ'));
    }

    /** Streams through the API after re-checking access at Google; served as a download, never inline. */
    public function download(Request $request, Project $project, string $id): Response|JsonResponse
    {
        $this->authorize('view', $project);
        if ($request->boolean('check')) {
            // Access pre-check (exists, not trashed, connection valid) so the UI can show errors.
            [$a] = $this->drive->fetch($project, $this->attachment($project, $id), false);

            return response()->json(['data' => ['status' => $a->status]]);
        }
        [$a, $body] = $this->drive->fetch($project, $this->attachment($project, $id), true);
        $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $a->name);

        return response((string) $body, 200, [
            'Content-Type' => $a->mime,
            'Content-Disposition' => "attachment; filename=\"{$ascii}\"; filename*=UTF-8''".rawurlencode($a->name),
            'X-Content-Type-Options' => 'nosniff',
            // No `sandbox` here: Chromium drops the filename of downloads from sandboxed responses.
            'Content-Security-Policy' => "default-src 'none'",
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function destroy(Project $project, string $id): JsonResponse
    {
        $this->authorize('manage', $project);
        $this->drive->delete($project, $this->attachment($project, $id));

        return response()->json(['data' => ['ok' => true]]);
    }
}
