<?php

namespace Tests\Feature;

use App\Domain\Publishing\AppUserAuth;
use App\Models\AppRecord;
use App\Models\Asset;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\PreviewLink;
use App\Models\Project;
use App\Models\Release;
use App\Services\AppDefinitionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** Phase 4: publish pipeline (J1/J9), runtime serving and host isolation (J8), app end-user accounts. */
class PublishingTest extends DataTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('assets');
        config(['studio.publishing.require_verified_email' => true]);
    }

    private function api(string $path): string
    {
        return "/api/v1/projects/{$this->project->id}{$path}";
    }

    private function publishAs(?Project $project = null)
    {
        $project ??= $this->project;

        return $this->actingAs($this->owner)->postJson("/api/v1/projects/{$project->id}/publish");
    }

    /** Reserve a slug and publish synchronously (queue driver is sync in tests). */
    private function goLive(string $slug = 'shop'): array
    {
        $this->actingAs($this->owner)->putJson($this->api('/subdomain'), ['slug' => $slug])->assertOk()
            ->assertJsonPath('data.hostname', "{$slug}.sites.localhost");

        return $this->publishAs()->assertStatus(202)->json('data');
    }

    private function site(string $slug = 'shop'): string
    {
        return "http://{$slug}.sites.localhost";
    }

    /** Runtime requests as a browser on the published host would make them. */
    private function runtime(string $method, string $slug, string $path, array $body = [], bool $csrfHeader = true)
    {
        $this->app['auth']->forgetGuards();
        $headers = ['Origin' => $this->site($slug), 'Accept' => 'application/json'];
        if ($csrfHeader) {
            $headers['X-Runtime-Request'] = '1';
        }

        return $this->withCredentials()->withHeaders($headers)->json($method, $this->site($slug).'/api/runtime/v1'.$path, $body);
    }

    public function test_j1_publish_pipeline_serves_frozen_release_on_subdomain(): void
    {
        $this->owner->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($this->owner)->putJson($this->api('/subdomain'), ['slug' => 'shop'])->assertOk();
        $this->publishAs()->assertStatus(422)->assertJsonPath('error.code', 'email_unverified');
        $this->owner->forceFill(['email_verified_at' => now()])->save();

        $deployment = $this->publishAs()->assertStatus(202)->json('data');
        $d = Deployment::query()->findOrFail($deployment['id']);
        $this->assertSame('succeeded', $d->status);
        $this->assertSame(['freeze', 'validate', 'security_scan', 'manifest', 'stage', 'smoke_test', 'switch', 'health_check'], array_column($d->steps, 'step'));
        $release = Release::query()->sole();
        $this->assertSame(1, $release->version);
        $this->assertSame($release->id, $this->project->fresh()->live_release_id);

        $html = $this->get($this->site().'/')->assertOk()->assertHeader('Content-Security-Policy')->getContent();
        $this->assertStringContainsString('<script id="__SITE__" type="application/json">', $html);
        $this->assertStringContainsString('<title>', $html);

        // Edits after publishing do not change the live site until the next publish (frozen release).
        $this->applyCommands([['type' => 'site.update', 'changes' => ['seo' => ['siteName' => 'ร้านใหม่']]]]);
        $this->assertStringNotContainsString('ร้านใหม่', $this->get($this->site().'/')->getContent());
        $this->actingAs($this->owner)->getJson($this->api('/publishing'))->assertOk()
            ->assertJsonPath('data.hasUnpublishedChanges', true)->assertJsonPath('data.live.version', 1)
            ->assertJsonPath('data.subdomain.url', 'https://shop.sites.localhost');
        $this->publishAs()->assertStatus(202);
        $this->assertStringContainsString('ร้านใหม่', $this->get($this->site().'/')->getContent());

        // Unknown hosts and unknown paths.
        $this->get('http://nobody.sites.localhost/')->assertNotFound();
        $this->get($this->site().'/missing')->assertNotFound()->assertSee('noindex', false);
    }

    public function test_published_document_never_exposes_workflows_or_project_id(): void
    {
        $this->applyCommands([['type' => 'workflow.upsert', 'index' => 0, 'workflow' => [
            'id' => 'w_secret', 'name' => 'แจ้งทีมงาน', 'enabled' => true,
            'trigger' => ['event' => 'record.created', 'modelId' => 'm_reg'], 'conditions' => [],
            'actions' => [['type' => 'email.send', 'to' => 'internal-team@corp.example', 'subject' => 'ใหม่', 'body' => 'x']],
        ]]]);
        $this->goLive();
        $html = $this->get($this->site().'/')->assertOk()->getContent();
        $this->assertStringNotContainsString('internal-team@corp.example', $html);
        $this->assertStringNotContainsString($this->project->id, $html);
    }

    public function test_j9_failed_publish_keeps_previous_release_live(): void
    {
        $this->goLive();
        $v1 = $this->project->fresh()->live_release_id;

        // Add an image, then delete the asset before publishing again.
        $assetId = $this->actingAs($this->owner)->post("/api/v1/workspaces/{$this->ws->id}/assets", ['file' => UploadedFile::fake()->image('p.png', 20, 20)])->json('data.id');
        $root = app(AppDefinitionService::class)->head($this->project)['document']['pages'][0]['rootId'];
        $this->applyCommands([['type' => 'component.insert', 'parentId' => $root, 'index' => 0, 'nodes' => [
            ['id' => 'img_1', 'type' => 'Image', 'props' => ['src' => "asset:{$assetId}", 'alt' => 'สินค้า'], 'styles' => (object) [], 'children' => []],
        ]]]);
        Asset::query()->whereKey($assetId)->update(['deleted_at' => now()]);

        $d = Deployment::query()->findOrFail($this->publishAs()->assertStatus(202)->json('data.id'));
        $this->assertSame('failed', $d->status);
        $this->assertSame('asset_unavailable', $d->error_code);
        $this->assertSame($v1, $this->project->fresh()->live_release_id);
        $this->assertSame('failed', Release::query()->where('version', 2)->value('status'));
        $this->get($this->site().'/')->assertOk();

        // The failed deployment is visible to the user with its reason.
        $this->actingAs($this->owner)->getJson($this->api("/deployments/{$d->id}"))->assertOk()
            ->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error.code', 'asset_unavailable');
    }

    public function test_release_assets_are_served_only_when_referenced_by_the_live_release(): void
    {
        $assetId = $this->actingAs($this->owner)->post("/api/v1/workspaces/{$this->ws->id}/assets", ['file' => UploadedFile::fake()->image('p.png', 20, 20)])->json('data.id');
        $other = $this->actingAs($this->owner)->post("/api/v1/workspaces/{$this->ws->id}/assets", ['file' => UploadedFile::fake()->image('q.png', 30, 30)])->json('data.id');
        $root = app(AppDefinitionService::class)->head($this->project)['document']['pages'][0]['rootId'];
        $this->applyCommands([['type' => 'component.insert', 'parentId' => $root, 'index' => 0, 'nodes' => [
            ['id' => 'img_1', 'type' => 'Image', 'props' => ['src' => "asset:{$assetId}", 'alt' => ''], 'styles' => (object) [], 'children' => []],
        ]]]);
        $this->goLive();
        $this->get($this->site()."/_assets/{$assetId}")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get($this->site()."/_assets/{$other}")->assertNotFound();
        $this->get("http://localhost/_assets/{$assetId}")->assertNotFound();
    }

    public function test_rollback_and_unpublish(): void
    {
        $this->goLive();
        $v1 = Release::query()->where('version', 1)->value('id');
        $this->applyCommands([['type' => 'site.update', 'changes' => ['seo' => ['siteName' => 'เวอร์ชันสอง']]]]);
        $this->publishAs()->assertStatus(202);
        $this->assertStringContainsString('เวอร์ชันสอง', $this->get($this->site().'/')->getContent());

        $this->actingAs($this->owner)->postJson($this->api('/rollback'), ['releaseId' => $v1])->assertOk()->assertJsonPath('data.action', 'rollback');
        $this->assertSame($v1, $this->project->fresh()->live_release_id);
        $this->assertStringNotContainsString('เวอร์ชันสอง', $this->get($this->site().'/')->getContent());
        $this->actingAs($this->owner)->postJson($this->api('/rollback'), ['releaseId' => $v1])->assertStatus(409);

        $this->actingAs($this->owner)->postJson($this->api('/unpublish'))->assertOk();
        $this->get($this->site().'/')->assertNotFound();
        $this->actingAs($this->owner)->postJson($this->api('/unpublish'))->assertStatus(409);
    }

    public function test_viewers_cannot_publish_and_outsiders_get_404(): void
    {
        $viewer = $this->addMember($this->ws, 'viewer');
        $this->actingAs($viewer)->putJson($this->api('/subdomain'), ['slug' => 'shop'])->assertForbidden();
        $this->actingAs($viewer)->postJson($this->api('/publish'))->assertForbidden();
        $this->actingAs($viewer)->getJson($this->api('/publishing'))->assertOk();
        [$stranger] = $this->tenant('Stranger');
        $this->actingAs($stranger)->getJson($this->api('/publishing'))->assertNotFound();
        $this->actingAs($stranger)->postJson($this->api('/publish'))->assertNotFound();
    }

    public function test_subdomain_rules_reserved_names_uniqueness_and_cooldown(): void
    {
        $this->actingAs($this->owner);
        $this->putJson($this->api('/subdomain'), ['slug' => 'admin'])->assertStatus(422)->assertJsonPath('error.code', 'reserved_slug');
        $this->putJson($this->api('/subdomain'), ['slug' => '-bad-'])->assertStatus(422)->assertJsonPath('error.code', 'invalid_slug');
        $this->putJson($this->api('/subdomain'), ['slug' => 'Shop'])->assertOk()->assertJsonPath('data.hostname', 'shop.sites.localhost');

        [$other, $otherWs] = $this->tenant('Other');
        $otherProject = $this->project($other, $otherWs);
        $this->actingAs($other)->putJson("/api/v1/projects/{$otherProject->id}/subdomain", ['slug' => 'shop'])->assertStatus(409)->assertJsonPath('error.code', 'slug_taken');

        // Renaming releases the old name, which then cools down for other organisations (takeover protection).
        $this->actingAs($this->owner)->putJson($this->api('/subdomain'), ['slug' => 'shop2'])->assertOk();
        $this->actingAs($other)->putJson("/api/v1/projects/{$otherProject->id}/subdomain", ['slug' => 'shop'])->assertStatus(409)->assertJsonPath('error.code', 'slug_cooling_down');
        $this->actingAs($this->owner)->putJson($this->api('/subdomain'), ['slug' => 'shop'])->assertOk(); // same org may reclaim
        $this->travel(31)->days();
        $this->actingAs($this->owner)->putJson($this->api('/subdomain'), ['slug' => 'shop3'])->assertOk();
        $this->travel(31)->days();
        $this->actingAs($other)->putJson("/api/v1/projects/{$otherProject->id}/subdomain", ['slug' => 'shop'])->assertOk();
    }

    public function test_j8_runtime_data_is_isolated_per_host(): void
    {
        $this->goLive('alpha');
        [$other, $otherWs] = $this->tenant('Other');
        $otherProject = $this->project($other, $otherWs);
        $this->actingAs($other)->putJson("/api/v1/projects/{$otherProject->id}/subdomain", ['slug' => 'beta'])->assertOk();
        $other->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($other)->postJson("/api/v1/projects/{$otherProject->id}/publish")->assertStatus(202);

        $this->runtime('POST', 'alpha', '/models/m_reg/records', ['data' => ['f_name' => 'ก', 'f_email' => 'a@example.com'], 'meta' => ['hp' => '', 'elapsedMs' => 3000]])->assertCreated();
        $record = AppRecord::query()->sole();
        $this->assertSame('production', $record->environment);
        $this->assertSame($this->project->id, $record->project_id);
        // beta has no such model; and preview data is not visible in production.
        $this->runtime('POST', 'beta', '/models/m_reg/records', ['data' => ['f_name' => 'ข', 'f_email' => 'b@example.com'], 'meta' => ['hp' => '', 'elapsedMs' => 3000]])->assertNotFound();
        $this->runtime('GET', 'alpha', '/models/m_reg/records')->assertStatus(403); // public cannot read
        $this->runtime('POST', 'nobody', '/models/m_reg/records', ['data' => [], 'meta' => []])->assertNotFound()->assertJsonPath('error.code', 'site_not_found');
        $this->withHeaders(['X-Runtime-Request' => '1'])->postJson('http://localhost/api/runtime/v1/models/m_reg/records', [])->assertNotFound();
    }

    public function test_runtime_writes_require_the_csrf_header(): void
    {
        $this->goLive();
        $this->runtime('POST', 'shop', '/models/m_reg/records', ['data' => ['f_name' => 'ก', 'f_email' => 'a@example.com'], 'meta' => ['hp' => '', 'elapsedMs' => 3000]], false)
            ->assertForbidden()->assertJsonPath('error.code', 'csrf_header_missing');
        $this->runtime('POST', 'shop', '/models/m_reg/records', ['data' => ['f_name' => 'ก', 'f_email' => 'a@example.com'], 'meta' => ['hp' => 'spam', 'elapsedMs' => 3000]])->assertStatus(422);
        $this->assertSame(0, AppRecord::query()->count());
    }

    public function test_app_end_user_accounts_sessions_and_member_permissions(): void
    {
        $this->goLive();
        $reg = $this->runtime('POST', 'shop', '/auth/register', ['name' => 'สมหญิง', 'email' => 'Ying@Example.com', 'password' => 'secret12345', 'password_confirmation' => 'secret12345'])
            ->assertCreated()->assertJsonPath('data.role', 'member')->assertJsonPath('data.email', 'ying@example.com');
        $cookie = $reg->getCookie(AppUserAuth::COOKIE, false);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertNull($cookie->getDomain()); // host-only: never shared with other sites
        $this->assertSame('lax', $cookie->getSameSite());
        $token = $cookie->getValue();

        $this->runtime('POST', 'shop', '/auth/register', ['name' => 'x', 'email' => 'ying@example.com', 'password' => 'secret12345', 'password_confirmation' => 'secret12345'])->assertStatus(422);

        // Member may read (RULES: member => read), public may not.
        $this->runtime('GET', 'shop', '/models/m_reg/records')->assertStatus(403);
        $this->withUnencryptedCookie(AppUserAuth::COOKIE, $token);
        $this->runtime('GET', 'shop', '/auth/me')->assertOk()->assertJsonPath('data.name', 'สมหญิง');
        $this->runtime('GET', 'shop', '/models/m_reg/records')->assertOk();

        // The session is bound to its project: the same token on another site is anonymous.
        [$other, $otherWs] = $this->tenant('Other');
        $otherProject = $this->project($other, $otherWs);
        $this->actingAs($other)->putJson("/api/v1/projects/{$otherProject->id}/subdomain", ['slug' => 'beta'])->assertOk();
        $other->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($other)->postJson("/api/v1/projects/{$otherProject->id}/publish")->assertStatus(202);
        $this->withUnencryptedCookie(AppUserAuth::COOKIE, $token);
        $this->runtime('GET', 'beta', '/auth/me')->assertOk()->assertJsonPath('data', null);

        // Disabling the account in the studio revokes its sessions immediately.
        $appUserId = $reg->json('data.id');
        $this->actingAs($this->owner)->patchJson($this->api("/app-users/{$appUserId}"), ['status' => 'disabled'])->assertOk();
        $this->withUnencryptedCookie(AppUserAuth::COOKIE, $token);
        $this->runtime('GET', 'shop', '/auth/me')->assertJsonPath('data', null);
        $this->runtime('POST', 'shop', '/auth/login', ['email' => 'ying@example.com', 'password' => 'secret12345'])->assertStatus(422);
        $this->actingAs($this->owner)->patchJson($this->api("/app-users/{$appUserId}"), ['status' => 'active', 'role' => 'admin'])->assertOk();
        $this->runtime('POST', 'shop', '/auth/login', ['email' => 'ying@example.com', 'password' => 'wrong-password1'])->assertStatus(422);
        $login = $this->runtime('POST', 'shop', '/auth/login', ['email' => 'ying@example.com', 'password' => 'secret12345'])->assertOk()->assertJsonPath('data.role', 'admin');
        $this->withUnencryptedCookie(AppUserAuth::COOKIE, $login->getCookie(AppUserAuth::COOKIE, false)->getValue());
        $this->runtime('POST', 'shop', '/auth/logout')->assertOk();
        $this->assertSame(0, \DB::table('app_user_sessions')->count());

        $this->actingAs($this->owner)->getJson($this->api('/app-users'))->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.password');
    }

    public function test_member_permissions_are_explicit_not_inherited_from_public(): void
    {
        $this->goLive();
        $token = $this->runtime('POST', 'shop', '/auth/register', ['name' => 'ก', 'email' => 'k@example.com', 'password' => 'secret12345', 'password_confirmation' => 'secret12345'])
            ->getCookie(AppUserAuth::COOKIE, false)->getValue();
        $this->withUnencryptedCookie(AppUserAuth::COOKIE, $token);
        // member has no create permission in RULES; public does — members are not "more than public" implicitly.
        $this->runtime('POST', 'shop', '/models/m_reg/records', ['data' => ['f_name' => 'ก', 'f_email' => 'k@example.com'], 'meta' => ['hp' => '', 'elapsedMs' => 3000]])->assertStatus(403);
    }

    public function test_preview_links_expire_and_can_be_revoked(): void
    {
        $this->actingAs($this->owner);
        $token = basename($this->postJson($this->api('/preview-links'), ['days' => 3])->assertCreated()->json('data.url'));
        $second = $this->postJson($this->api('/preview-links'), ['days' => 1])->assertCreated();
        [$id, $revokedToken] = [$second->json('data.id'), basename($second->json('data.url'))];
        $this->postJson($this->api('/preview-links'), ['days' => 365])->assertStatus(422);
        $this->deleteJson($this->api("/preview-links/{$id}"))->assertOk();
        $this->assertCount(2, $this->getJson($this->api('/preview-links'))->json('data'));
        $this->assertNotNull(PreviewLink::query()->whereKey($id)->value('revoked_at'));
        $this->assertSame(hash('sha256', $token), PreviewLink::query()->where('revoked_at', null)->value('token_hash')); // only hashes stored

        $this->app['auth']->forgetGuards(); // public endpoint: the token alone is sufficient
        $this->getJson("/api/v1/preview-links/{$token}")->assertOk()->assertHeader('X-Robots-Tag', 'noindex')
            ->assertJsonPath('data.document.workflows', []);
        $this->getJson('/api/v1/preview-links/'.str_repeat('a', 40))->assertNotFound();
        $this->getJson("/api/v1/preview-links/{$revokedToken}")->assertNotFound();
        $this->travel(4)->days();
        $this->getJson("/api/v1/preview-links/{$token}")->assertNotFound();
    }

    public function test_studio_data_admin_separates_preview_and_production(): void
    {
        $this->actingAs($this->owner)->getJson($this->records('?env=production'))->assertNotFound()->assertJsonPath('error.code', 'not_published');
        $this->createRecord(['f_name' => 'ทดลอง', 'f_email' => 'preview@example.com']);
        $this->goLive();
        $this->runtime('POST', 'shop', '/models/m_reg/records', ['data' => ['f_name' => 'ลูกค้าจริง', 'f_email' => 'preview@example.com'], 'meta' => ['hp' => '', 'elapsedMs' => 3000]])
            ->assertCreated(); // unique keys are per environment

        $this->actingAs($this->owner)->getJson($this->records('?env=production'))->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.data.f_name', 'ลูกค้าจริง')->assertJsonPath('data.0.source', 'form');
        $this->actingAs($this->owner)->getJson($this->records())->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.data.f_name', 'ทดลอง');
        $prodId = AppRecord::query()->where('environment', 'production')->value('id');
        $this->actingAs($this->owner)->getJson($this->records("/{$prodId}"))->assertNotFound();
        $this->actingAs($this->owner)->patchJson($this->records("/{$prodId}?env=production"), ['data' => ['f_seats' => 2], 'version' => 1])->assertOk();
        $csv = $this->actingAs($this->owner)->get("/api/v1/projects/{$this->project->id}/data/m_reg/export?env=production")->assertOk();
        $this->assertStringContainsString('ลูกค้าจริง', $csv->streamedContent());
    }

    public function test_platform_hosts_are_never_treated_as_sites(): void
    {
        $this->get('http://localhost/')->assertOk()->assertJsonPath('service', 'ai-studio-cloud/platform-api');
        $this->get('http://localhost/some/page')->assertNotFound();
        $this->assertSame(0, Domain::query()->count());
    }
}
