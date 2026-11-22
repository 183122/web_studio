<?php

namespace App\Domain\Integrations\Google;

use App\Models\GoogleConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Str;

/** Drive v3 with the `drive.file` scope: the app can only see files/folders it created. */
class DriveApi
{
    public const FOLDER_MIME = 'application/vnd.google-apps.folder';

    public function __construct(private readonly GoogleClient $google) {}

    /** @return list<array{id: string, name: string}> folders created by this app */
    public function folders(GoogleConnection $c): array
    {
        $res = $this->google->call($c, fn (PendingRequest $r) => $r->get(GoogleEndpoints::url('drive').'/files', [
            'q' => "mimeType='".self::FOLDER_MIME."' and trashed=false", 'fields' => 'files(id,name)', 'pageSize' => 100, 'orderBy' => 'name',
        ]));

        return array_map(fn ($f) => ['id' => (string) $f['id'], 'name' => (string) $f['name']], $res->json('files') ?? []);
    }

    public function createFolder(GoogleConnection $c, string $name): array
    {
        $res = $this->google->call($c, fn (PendingRequest $r) => $r->post(GoogleEndpoints::url('drive').'/files?fields=id,name', ['name' => $name, 'mimeType' => self::FOLDER_MIME]));

        return ['id' => (string) $res->json('id'), 'name' => (string) $res->json('name')];
    }

    public function upload(GoogleConnection $c, string $folderId, string $name, string $mime, string $contents, array $appProperties): array
    {
        $boundary = 'studio'.Str::random(24);
        $meta = json_encode(['name' => $name, 'parents' => [$folderId], 'mimeType' => $mime, 'appProperties' => $appProperties], JSON_UNESCAPED_UNICODE);
        $body = "--{$boundary}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n{$meta}\r\n--{$boundary}\r\nContent-Type: {$mime}\r\n\r\n{$contents}\r\n--{$boundary}--";
        $res = $this->google->call($c, fn (PendingRequest $r) => $r->withBody($body, "multipart/related; boundary={$boundary}")
            ->post(GoogleEndpoints::url('upload').'/files?uploadType=multipart&fields=id,name,mimeType,size'));

        return ['id' => (string) $res->json('id'), 'name' => (string) $res->json('name'), 'mime' => (string) ($res->json('mimeType') ?? $mime), 'size' => (int) ($res->json('size') ?? strlen($contents))];
    }

    /** Fresh metadata on every access (permission + existence check). */
    public function metadata(GoogleConnection $c, string $fileId): array
    {
        $res = $this->google->call($c, fn (PendingRequest $r) => $r->get(GoogleEndpoints::url('drive').'/files/'.rawurlencode($fileId), ['fields' => 'id,name,mimeType,size,trashed']));
        if ($res->json('trashed') === true) {
            throw new GoogleApiException('not_found', 'file is in trash');
        }

        return $res->json();
    }

    public function download(GoogleConnection $c, string $fileId): string
    {
        return $this->google->call($c, fn (PendingRequest $r) => $r->accept('*/*')->get(GoogleEndpoints::url('drive').'/files/'.rawurlencode($fileId), ['alt' => 'media']))->body();
    }

    public function delete(GoogleConnection $c, string $fileId): void
    {
        $this->google->call($c, fn (PendingRequest $r) => $r->delete(GoogleEndpoints::url('drive').'/files/'.rawurlencode($fileId)));
    }
}
