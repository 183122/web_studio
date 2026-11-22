<?php

namespace App\Domain\Integrations\Drive;

use App\Domain\Integrations\Google\DriveApi;
use App\Domain\Integrations\Google\GoogleApiException;
use App\Domain\Integrations\Google\GoogleClient;
use App\Models\AppRecord;
use App\Models\Project;
use App\Models\RecordAttachment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Scanning\MalwareScanner;
use App\Support\ApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Record attachments stored in the workspace's Google Drive (M24), `drive.file` scope only.
 * Every access re-checks the file at Google (exists, not trashed, still accessible) and the
 * caller's project permission; revoked connections and deleted files are reported, not hidden.
 */
class DriveAttachmentService
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** Extension → MIME types accepted (sniffed content must match). No HTML/SVG/scripts/executables. */
    public const ALLOWED = [
        'pdf' => ['application/pdf'],
        'png' => ['image/png'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'webp' => ['image/webp'], 'gif' => ['image/gif'],
        'txt' => ['text/plain'], 'csv' => ['text/plain', 'text/csv'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'zip' => ['application/zip'],
    ];

    public function __construct(
        private readonly GoogleClient $google,
        private readonly DriveApi $drive,
        private readonly MalwareScanner $scanner,
        private readonly AuditLogger $audit,
    ) {}

    public function folder(Project $project): ?object
    {
        return DB::table('drive_folders')->where('project_id', $project->id)->first();
    }

    /** Folders this app created in the connected Drive (the only ones `drive.file` can see). */
    public function folders(Project $project): array
    {
        return $this->drive->folders($this->google->connectionFor($project->workspace_id, 'drive'));
    }

    public function chooseFolder(Project $project, ?string $folderId, ?string $newName): object
    {
        $c = $this->google->connectionFor($project->workspace_id, 'drive');
        if ($newName !== null) {
            $folder = $this->drive->createFolder($c, $newName);
        } else {
            $folder = collect($this->drive->folders($c))->firstWhere('id', $folderId);
            if ($folder === null) {
                throw ApiException::unprocessable('folder_not_found', 'ไม่พบโฟลเดอร์นี้ (เลือกได้เฉพาะโฟลเดอร์ที่สร้างผ่านระบบนี้)');
            }
        }
        DB::table('drive_folders')->updateOrInsert(['project_id' => $project->id], ['folder_id' => $folder['id'], 'name' => $folder['name'], 'updated_at' => now(), 'created_at' => now()]);
        $this->audit->record('drive.folder_selected', $project, ['folder' => $folder['name']]);

        return $this->folder($project);
    }

    public function upload(Project $project, AppRecord $record, UploadedFile $file, User $user): RecordAttachment
    {
        $folder = $this->folder($project) ?? throw ApiException::unprocessable('folder_required', 'กรุณาเลือกโฟลเดอร์ Google Drive ของโปรเจกต์ก่อน');
        $ext = strtolower($file->getClientOriginalExtension());
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()); // sniff content, never trust the client
        if (! isset(self::ALLOWED[$ext]) || ! in_array($mime, self::ALLOWED[$ext], true)) {
            throw ApiException::unprocessable('unsupported_file_type', 'ไม่รองรับไฟล์ประเภทนี้ (รองรับ PDF, รูปภาพ, Office, TXT/CSV, ZIP)');
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw ApiException::unprocessable('file_too_large', 'ไฟล์ต้องมีขนาดไม่เกิน 10 MB');
        }
        $contents = (string) file_get_contents($file->getRealPath());
        if ($this->scanner->scan($contents) === 'infected') {
            $this->audit->record('drive.upload_rejected_malware', $project, ['record_id' => $record->id]);
            throw ApiException::unprocessable('malware_detected', 'ระบบตรวจพบมัลแวร์ในไฟล์ จึงไม่อนุญาตให้อัปโหลด');
        }
        $name = mb_substr(preg_replace('/[\x00-\x1F\x7F\/\\\\]/u', '_', $file->getClientOriginalName()) ?: 'file.'.$ext, 0, 200);
        $mimeOut = self::ALLOWED[$ext][0];
        $c = $this->google->connectionFor($project->workspace_id, 'drive');
        $uploaded = $this->drive->upload($c, $folder->folder_id, $name, $mimeOut, $contents, ['studioProject' => $project->id, 'studioRecord' => $record->id]);
        $attachment = RecordAttachment::create([
            'project_id' => $project->id, 'record_id' => $record->id, 'drive_file_id' => $uploaded['id'], 'name' => $name,
            'mime' => $mimeOut, 'size' => strlen($contents), 'status' => 'available', 'uploaded_by' => $user->id, 'checked_at' => now(),
        ]);
        $this->audit->record('drive.file_uploaded', $project, ['record_id' => $record->id, 'attachment_id' => $attachment->id, 'size' => strlen($contents)]);

        return $attachment;
    }

    /** Re-validates at Google; updates status on missing/revoked. @return array{0: RecordAttachment, 1: ?string} [attachment, contents] */
    public function fetch(Project $project, RecordAttachment $a, bool $withContents): array
    {
        try {
            $c = $this->google->connectionFor($project->workspace_id, 'drive');
            $this->drive->metadata($c, $a->drive_file_id);
            $body = $withContents ? $this->drive->download($c, $a->drive_file_id) : null;
            $a->forceFill(['status' => 'available', 'checked_at' => now()])->save();

            return [$a, $body];
        } catch (GoogleApiException $e) {
            $status = match ($e->kind) {
                'not_found' => 'missing',
                'auth', 'permission', 'not_connected', 'scope_missing' => 'access_lost',
                default => $a->status,
            };
            $a->forceFill(['status' => $status, 'checked_at' => now()])->save();
            throw $e;
        }
    }

    public function delete(Project $project, RecordAttachment $a): void
    {
        try {
            $this->drive->delete($this->google->connectionFor($project->workspace_id, 'drive'), $a->drive_file_id);
        } catch (GoogleApiException $e) {
            if (! in_array($e->kind, ['not_found', 'auth', 'not_connected', 'permission', 'scope_missing'], true)) {
                throw $e;
            }
        }
        $a->delete();
        $this->audit->record('drive.file_removed', $project, ['attachment_id' => $a->id]);
    }
}
