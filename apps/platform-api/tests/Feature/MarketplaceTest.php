<?php

namespace Tests\Feature;

use App\Jobs\SendBillingEmail;
use App\Models\AppRecord;
use App\Models\Asset;
use App\Models\Project;
use App\Models\Template;
use App\Models\User;
use App\Services\AppDefinitionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/** M12: submit → sanitize → moderate → publish (versioned, licensed) → install with copied files. */
class MarketplaceTest extends DataTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('assets');
        Queue::fake([SendBillingEmail::class]);
        [$this->admin] = $this->tenant('Moderator');
        $this->artisan('studio:platform-admin', ['email' => $this->admin->email]);
        $this->admin->refresh();
    }

    private function withImageAndWorkflows(): string
    {
        $assetId = $this->actingAs($this->owner)->post("/api/v1/workspaces/{$this->ws->id}/assets", ['file' => UploadedFile::fake()->image('hero.png', 40, 30)])->json('data.id');
        $root = app(AppDefinitionService::class)->head($this->project)['document']['pages'][0]['rootId'];
        $this->applyCommands([
            ['type' => 'component.insert', 'parentId' => $root, 'index' => 0, 'nodes' => [['id' => 'img_1', 'type' => 'Image', 'props' => ['src' => "asset:{$assetId}", 'alt' => 'hero'], 'styles' => (object) [], 'children' => []]]],
            ['type' => 'workflow.upsert', 'index' => 0, 'workflow' => [
                'id' => 'w_notify', 'name' => 'แจ้งทีม', 'enabled' => true, 'trigger' => ['event' => 'record.created', 'modelId' => 'm_reg'], 'conditions' => [],
                'actions' => [
                    ['type' => 'email.send', 'to' => 'owner-private@corp.example', 'subject' => 'ใหม่', 'body' => 'x'],
                    ['type' => 'email.send', 'to' => 'field:f_email', 'subject' => 'ขอบคุณ', 'body' => 'ได้รับแล้ว'],
                ],
            ]],
        ]);

        return $assetId;
    }

    private function submit(array $over = [])
    {
        return $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/template-submissions", array_merge([
            'name' => 'Registration Kit', 'description' => 'แบบฟอร์มลงทะเบียนพร้อมหน้าเว็บ', 'category' => 'event', 'license' => 'free', 'confirmRights' => true,
        ], $over));
    }

    public function test_submission_is_sanitized_moderated_and_installs_with_copied_assets(): void
    {
        $this->withImageAndWorkflows();
        $this->submit(['confirmRights' => false])->assertStatus(422);
        $sub = $this->submit()->assertCreated()->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.sanitization.emailActions', 1)->assertJsonPath('data.assetCount', 1)->json('data');
        $this->submit()->assertStatus(409); // one pending submission per project

        // Not listed before approval; moderators see the sanitized document.
        $this->getJson('/api/v1/templates?source=community')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->owner)->getJson('/api/v1/admin/template-submissions')->assertNotFound();
        $doc = $this->actingAs($this->admin)->getJson("/api/v1/admin/template-submissions/{$sub['id']}")->assertOk()->json('data.document');
        $this->assertStringNotContainsString('owner-private@corp.example', json_encode($doc));
        $this->assertFalse($doc['workflows'][0]['enabled']);
        $this->assertSame([], $doc['dataModels'][0]['records'] ?? []);

        $slug = $this->actingAs($this->admin)->postJson("/api/v1/admin/template-submissions/{$sub['id']}", ['decision' => 'approve', 'reason' => 'ตรวจแล้ว เหมาะสม'])
            ->assertOk()->assertJsonPath('data.status', 'approved')->json('data.templateSlug');
        Queue::assertPushed(SendBillingEmail::class, fn ($j) => $j->to === $this->owner->email);
        $this->getJson('/api/v1/templates?source=community')->assertOk()->assertJsonPath('data.0.slug', $slug)
            ->assertJsonPath('data.0.license', 'free')->assertJsonPath('data.0.version', 1)->assertJsonPath('data.0.authorName', $this->ws->organization->name);

        // Another tenant installs it: files are copied into their workspace, refs rewritten, no records copied.
        [$other, $otherWs] = $this->tenant('Installer');
        $this->createRecord(['f_name' => 'ข้อมูลของผู้เขียน', 'f_email' => 'author-data@example.com']);
        $pid = $this->actingAs($other)->postJson("/api/v1/workspaces/{$otherWs->id}/projects", ['name' => 'จากแกลเลอรี', 'templateSlug' => $slug])->assertCreated()->json('data.id');
        $installed = app(AppDefinitionService::class)->head(Project::query()->find($pid))['document'];
        $newRef = $installed['components']['img_1']['props']['src'];
        $newAsset = Asset::query()->findOrFail(substr($newRef, 6));
        $this->assertSame($otherWs->id, $newAsset->workspace_id);
        Storage::disk('assets')->assertExists($newAsset->path);
        $this->assertSame([substr($newRef, 6)], $installed['assets']);
        $this->assertSame(0, AppRecord::query()->where('project_id', $pid)->count());
        $this->assertSame(1, Template::query()->where('slug', $slug)->value('install_count'));
        $this->actingAs($other)->get("/api/v1/assets/{$newAsset->id}/content")->assertStatus(403); // still signed-URL only
    }

    public function test_new_version_rejection_delisting_and_reporting(): void
    {
        $sub = $this->submit()->json('data');
        $slug = $this->actingAs($this->admin)->postJson("/api/v1/admin/template-submissions/{$sub['id']}", ['decision' => 'approve', 'reason' => 'ตรวจแล้ว'])->assertOk()->json('data.templateSlug');

        $v2 = $this->submit(['changelog' => 'เพิ่มหน้าใหม่'])->assertCreated()->json('data');
        $this->assertNotNull($v2['templateId']);
        $this->actingAs($this->admin)->postJson("/api/v1/admin/template-submissions/{$v2['id']}", ['decision' => 'reject', 'reason' => 'มีลิงก์เสีย'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->getJson("/api/v1/templates/{$slug}")->assertOk()->assertJsonPath('data.version', 1);
        $v3 = $this->submit(['changelog' => 'แก้ลิงก์แล้ว'])->json('data');
        $this->actingAs($this->admin)->postJson("/api/v1/admin/template-submissions/{$v3['id']}", ['decision' => 'approve', 'reason' => 'ตรวจแล้ว'])->assertOk();
        $this->getJson("/api/v1/templates/{$slug}")->assertOk()->assertJsonPath('data.version', 2);

        $this->actingAs($this->owner)->getJson("/api/v1/organizations/{$this->ws->organization_id}/templates")->assertOk()
            ->assertJsonCount(3, 'data.submissions')->assertJsonPath('data.templates.0.version', 2);

        [$reporter] = $this->tenant('Reporter');
        $this->actingAs($reporter)->postJson("/api/v1/templates/{$slug}/report", ['category' => 'spam', 'details' => 'เทมเพลตนี้มีลิงก์โฆษณา'])->assertCreated();
        $tid = Template::query()->where('slug', $slug)->value('id');
        $this->actingAs($this->admin)->postJson("/api/v1/admin/templates/{$tid}/delist", ['reason' => 'สแปม'])->assertOk();
        $this->getJson("/api/v1/templates/{$slug}")->assertNotFound();
        $this->actingAs($reporter)->postJson("/api/v1/workspaces/{$this->tenant('X')[1]->id}/projects", ['name' => 'x', 'templateSlug' => $slug]);
        $this->assertSame(0, Template::query()->where('slug', $slug)->value('install_count'));
    }

    public function test_only_project_managers_submit_and_org_admins_delist(): void
    {
        $viewer = $this->addMember($this->ws, 'viewer');
        $this->actingAs($viewer)->postJson("/api/v1/projects/{$this->project->id}/template-submissions", ['name' => 'abc', 'description' => 'ข้อความยาวพอสมควร', 'category' => 'event', 'license' => 'free', 'confirmRights' => true])->assertForbidden();
        [$stranger] = $this->tenant('S');
        $this->actingAs($stranger)->postJson("/api/v1/projects/{$this->project->id}/template-submissions", [])->assertNotFound();
        $sub = $this->submit()->json('data');
        $this->actingAs($this->admin)->postJson("/api/v1/admin/template-submissions/{$sub['id']}", ['decision' => 'approve', 'reason' => 'ตรวจแล้ว']);
        $tid = Template::query()->where('source', 'community')->value('id');
        $this->actingAs($stranger)->postJson("/api/v1/organizations/{$this->ws->organization_id}/templates/{$tid}/delist")->assertNotFound();
        $this->actingAs($this->owner)->postJson("/api/v1/organizations/{$this->ws->organization_id}/templates/{$tid}/delist")->assertOk();
        $this->assertFalse(Template::query()->find($tid)->is_published);
    }
}
