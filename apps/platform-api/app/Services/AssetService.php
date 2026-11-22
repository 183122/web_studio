<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Scanning\MalwareScanner;
use App\Support\ApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Image uploads: content sniffing (not client MIME), dimension limits (decompression-bomb guard),
 * re-encoding through GD (strips EXIF/GPS metadata and polyglot payloads), malware scan,
 * per-tenant storage prefixes, de-duplication and storage quota.
 */
class AssetService
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly MalwareScanner $scanner,
        private readonly AuditLogger $audit,
    ) {}

    public function upload(Workspace $workspace, User $user, UploadedFile $file): array
    {
        $raw = (string) file_get_contents($file->getRealPath());
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($raw) ?: 'application/octet-stream';
        $allowed = config('studio.assets.allowed_mimes');
        if (! array_key_exists($mime, $allowed)) {
            throw ApiException::unprocessable('unsupported_file_type', 'รองรับเฉพาะไฟล์ JPEG, PNG และ WebP', ['detected' => $mime]);
        }
        $info = @getimagesizefromstring($raw);
        if ($info === false) {
            throw ApiException::unprocessable('invalid_image', 'ไฟล์รูปภาพเสียหายหรืออ่านไม่ได้');
        }
        [$width, $height] = $info;
        if ($width * $height > (int) config('studio.assets.max_pixels')) {
            throw ApiException::unprocessable('image_too_large', 'ขนาดภาพ (พิกเซล) ใหญ่เกินกำหนด');
        }

        $sha = hash('sha256', $raw);
        $existing = Asset::query()->where('workspace_id', $workspace->id)->where('sha256', $sha)->first();
        if ($existing !== null) {
            return ['asset' => $existing, 'deduplicated' => true];
        }

        $encoded = $this->reencode($raw, $mime);
        $status = $this->scanner->scan($raw);
        if ($status === 'infected') {
            $this->audit->record('asset.rejected_malware', null, ['sha256' => $sha], $workspace->organization_id, $workspace->id);
            throw ApiException::unprocessable('malware_detected', 'ระบบตรวจพบมัลแวร์ในไฟล์ จึงไม่อนุญาตให้อัปโหลด');
        }

        $asset = DB::transaction(function () use ($workspace, $user, $file, $mime, $allowed, $encoded, $sha, $width, $height, $status) {
            $org = Organization::query()->whereKey($workspace->organization_id)->lockForUpdate()->firstOrFail();
            $this->entitlements->assertStorageAvailable($org, strlen($encoded));
            $id = strtolower((string) Str::ulid());
            $disk = config('studio.assets.disk');
            $path = "tenants/{$org->id}/{$workspace->id}/assets/{$id}.{$allowed[$mime]}";
            Storage::disk($disk)->put($path, $encoded);

            $asset = new Asset([
                'organization_id' => $org->id,
                'workspace_id' => $workspace->id,
                'uploaded_by' => $user->id,
                'disk' => $disk,
                'path' => $path,
                'original_name' => Str::limit($this->safeName($file->getClientOriginalName()), 250, ''),
                'mime' => $mime,
                'size_bytes' => strlen($encoded),
                'sha256' => $sha,
                'width' => $width,
                'height' => $height,
                'scan_status' => $status,
            ]);
            $asset->id = $id;
            $asset->save();

            return $asset;
        });
        $this->audit->record('asset.uploaded', $asset, ['size' => $asset->size_bytes, 'mime' => $mime, 'scan' => $status]);

        return ['asset' => $asset, 'deduplicated' => false];
    }

    public function delete(Asset $asset): void
    {
        Storage::disk($asset->disk)->delete($asset->path);
        $asset->delete();
        $this->audit->record('asset.deleted', $asset);
    }

    /** Relative, short-lived signed URL (works behind any same-origin proxy). */
    public function signedUrl(Asset $asset): string
    {
        return URL::temporarySignedRoute(
            'assets.content',
            now()->addMinutes((int) config('studio.assets.signed_url_ttl_minutes')),
            ['asset' => $asset->id],
            absolute: false,
        );
    }

    private function reencode(string $raw, string $mime): string
    {
        $image = @imagecreatefromstring($raw);
        if ($image === false) {
            throw ApiException::unprocessable('invalid_image', 'ไฟล์รูปภาพเสียหายหรืออ่านไม่ได้');
        }
        ob_start();
        match ($mime) {
            'image/jpeg' => imagejpeg($image, null, 88),
            'image/png' => (function () use ($image) {
                imagesavealpha($image, true);
                imagepng($image, null, 6);
            })(),
            'image/webp' => imagewebp($image, null, 85),
        };
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    private function safeName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '', $name) ?? 'file';

        return $name !== '' ? $name : 'file';
    }
}
