<?php

namespace Tests\Feature;

use App\Models\RecordAttachment;
use Illuminate\Http\UploadedFile;

/** M24 / J5: Drive folder → upload attachment → see file → revoke/delete handled. */
class DriveAttachmentTest extends IntegrationTestCase
{
    private array $record;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectGoogle(['drive']);
        $this->record = $this->createRecord(['f_name' => 'สมชาย', 'f_email' => 's@example.com']);
    }

    private function api(string $path): string
    {
        return "/api/v1/projects/{$this->project->id}{$path}";
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('ใบสมัคร.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    }

    private function upload(?UploadedFile $file = null)
    {
        return $this->actingAs($this->owner)->post($this->api("/data/m_reg/records/{$this->record['id']}/attachments"), ['file' => $file ?? $this->pdf()], ['Accept' => 'application/json']);
    }

    public function test_j5_choose_folder_upload_view_and_download(): void
    {
        $this->upload()->assertStatus(422)->assertJsonPath('error.code', 'folder_required');
        $this->actingAs($this->owner)->putJson($this->api('/drive/folder'), ['newName' => 'ไฟล์ผู้สมัคร'])->assertOk()->assertJsonPath('data.folder.name', 'ไฟล์ผู้สมัคร');
        $this->actingAs($this->owner)->getJson($this->api('/drive?withFolders=1'))->assertOk()->assertJsonPath('data.folders.0.name', 'ไฟล์ผู้สมัคร');

        $a = $this->upload()->assertCreated()->assertJsonPath('data.name', 'ใบสมัคร.pdf')->assertJsonPath('data.status', 'available')->json('data');
        $stored = collect($this->google->files)->first(fn ($f) => $f['name'] === 'ใบสมัคร.pdf');
        $this->assertStringStartsWith('%PDF', $stored['content']);
        $this->actingAs($this->owner)->getJson($this->api("/data/m_reg/records/{$this->record['id']}/attachments"))->assertOk()->assertJsonCount(1, 'data');

        $dl = $this->actingAs($this->owner)->get($a['downloadUrl'])->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('attachment;', $dl->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $dl->getContent());

        // A viewer may see/download but not upload or delete.
        $viewer = $this->addMember($this->ws, 'viewer');
        $this->actingAs($viewer)->get($a['downloadUrl'])->assertOk();
        $this->actingAs($viewer)->deleteJson($this->api("/attachments/{$a['id']}"))->assertForbidden();
        // Outsiders get nothing.
        [$stranger] = $this->tenant('Stranger');
        $this->actingAs($stranger)->get($a['downloadUrl'])->assertNotFound();

        $this->actingAs($this->owner)->deleteJson($this->api("/attachments/{$a['id']}"))->assertOk();
        $this->assertNull(collect($this->google->files)->first(fn ($f) => $f['name'] === 'ใบสมัคร.pdf'));
    }

    public function test_deleted_file_and_revoked_access_are_reported(): void
    {
        $this->actingAs($this->owner)->putJson($this->api('/drive/folder'), ['newName' => 'แนบ'])->assertOk();
        $a = $this->upload()->json('data');
        $fileId = RecordAttachment::query()->find($a['id'])->drive_file_id;

        unset($this->google->files[$fileId]); // deleted in Drive by someone
        $this->actingAs($this->owner)->get($a['downloadUrl'])->assertNotFound()->assertJsonPath('error.code', 'google_not_found');
        $this->assertSame('missing', RecordAttachment::query()->find($a['id'])->status);

        $b = $this->upload()->json('data');
        $this->google->revoked = true; // user revoked the app in their Google account
        $this->actingAs($this->owner)->get($b['downloadUrl'])->assertStatus(422)->assertJsonPath('error.code', 'google_auth');
        $this->assertSame('access_lost', RecordAttachment::query()->find($b['id'])->status);
    }

    public function test_rejects_unsafe_file_types_and_folders_not_created_by_the_app(): void
    {
        $this->actingAs($this->owner)->putJson($this->api('/drive/folder'), ['folderId' => 'someone-elses-folder'])->assertStatus(422)->assertJsonPath('error.code', 'folder_not_found');
        $this->actingAs($this->owner)->putJson($this->api('/drive/folder'), ['newName' => 'แนบ'])->assertOk();
        $this->upload(UploadedFile::fake()->createWithContent('x.html', '<script>alert(1)</script>'))->assertStatus(422)->assertJsonPath('error.code', 'unsupported_file_type');
        $this->upload(UploadedFile::fake()->createWithContent('fake.pdf', 'MZ'.str_repeat("\0", 64)))->assertStatus(422);
        $this->assertSame(0, RecordAttachment::query()->count());
    }

    public function test_drive_scope_is_required(): void
    {
        $this->actingAs($this->owner)->deleteJson("/api/v1/workspaces/{$this->ws->id}/integrations/google")->assertOk();
        $this->google->revoked = false;
        $this->google->grantedScope = 'openid email https://www.googleapis.com/auth/spreadsheets';
        $this->connectGoogle(['sheets']);
        $this->actingAs($this->owner)->putJson($this->api('/drive/folder'), ['newName' => 'แนบ'])->assertStatus(422)->assertJsonPath('error.code', 'google_scope_missing');
    }
}
