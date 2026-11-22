<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AuditLog;
use App\Services\Scanning\MalwareScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AssetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('assets');
    }

    public function test_upload_reencodes_stores_under_tenant_prefix_and_serves_via_signed_url(): void
    {
        [$user, $ws] = $this->tenant();
        $res = $this->actingAs($user)->post("/api/v1/workspaces/{$ws->id}/assets", ['file' => UploadedFile::fake()->image('ภาพ.jpg', 64, 48)])
            ->assertCreated();
        $res->assertJsonPath('data.mime', 'image/jpeg')
            ->assertJsonPath('data.width', 64)
            ->assertJsonPath('data.scanStatus', 'not_scanned')
            ->assertJsonPath('data.name', 'ภาพ.jpg');
        $asset = Asset::query()->findOrFail($res->json('data.id'));
        $this->assertStringStartsWith("tenants/{$ws->organization_id}/{$ws->id}/assets/", $asset->path);
        Storage::disk('assets')->assertExists($asset->path);
        $this->assertArrayNotHasKey('path', $res->json('data'));

        $url = $res->json('data.url');
        $this->assertStringStartsWith('/api/v1/assets/', $url);
        $this->app['auth']->forgetGuards(); // the signed URL alone must be sufficient (no session)
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get(preg_replace('/signature=[a-f0-9]+/', 'signature=deadbeef', $url))->assertStatus(403);
        $this->get("/api/v1/assets/{$asset->id}/content")->assertStatus(403);
        $this->assertTrue(AuditLog::query()->where('action', 'asset.uploaded')->exists());
    }

    public function test_rejects_non_images_svg_and_spoofed_extensions(): void
    {
        [$user, $ws] = $this->tenant();
        $this->actingAs($user);
        $svg = UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>');
        $this->post("/api/v1/workspaces/{$ws->id}/assets", ['file' => $svg])->assertStatus(422)->assertJsonPath('error.code', 'unsupported_file_type');
        $php = UploadedFile::fake()->createWithContent('shell.jpg', '<?php system($_GET["c"]); ?>');
        $this->post("/api/v1/workspaces/{$ws->id}/assets", ['file' => $php])->assertStatus(422)->assertJsonPath('error.code', 'unsupported_file_type');
        $this->post("/api/v1/workspaces/{$ws->id}/assets", [])->assertStatus(422);
        $this->assertSame(0, Asset::query()->count());
    }

    public function test_deduplicates_identical_uploads_within_workspace(): void
    {
        [$user, $ws] = $this->tenant();
        $file = UploadedFile::fake()->image('a.png', 30, 30);
        $content = file_get_contents($file->getRealPath());
        $first = $this->actingAs($user)->post("/api/v1/workspaces/{$ws->id}/assets", ['file' => UploadedFile::fake()->createWithContent('a.png', $content)])->assertCreated();
        $second = $this->post("/api/v1/workspaces/{$ws->id}/assets", ['file' => UploadedFile::fake()->createWithContent('b.png', $content)])->assertOk();
        $second->assertJsonPath('meta.deduplicated', true);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
    }

    public function test_storage_quota_is_enforced(): void
    {
        [$user, $ws] = $this->tenant();
        $plan = $ws->organization->plan;
        $plan->update(['entitlements' => array_merge($plan->entitlements, ['storage_bytes' => 10])]);
        $this->actingAs($user)->post("/api/v1/workspaces/{$ws->id}/assets", ['file' => UploadedFile::fake()->image('a.png', 50, 50)])
            ->assertStatus(422)->assertJsonPath('error.code', 'quota_exceeded');
    }

    public function test_infected_files_are_rejected(): void
    {
        $this->app->instance(MalwareScanner::class, new class implements MalwareScanner
        {
            public function scan(string $contents): string
            {
                return 'infected';
            }
        });
        [$user, $ws] = $this->tenant();
        $this->actingAs($user)->post("/api/v1/workspaces/{$ws->id}/assets", ['file' => UploadedFile::fake()->image('a.png', 10, 10)])
            ->assertStatus(422)->assertJsonPath('error.code', 'malware_detected');
        $this->assertSame(0, Asset::query()->count());
    }

    public function test_listing_search_and_delete(): void
    {
        [$user, $ws] = $this->tenant();
        $this->actingAs($user);
        $id = $this->post("/api/v1/workspaces/{$ws->id}/assets", ['file' => UploadedFile::fake()->image('logo.png', 10, 10)])->json('data.id');
        $this->post("/api/v1/workspaces/{$ws->id}/assets", ['file' => UploadedFile::fake()->image('banner.png', 12, 10)]);
        $this->getJson("/api/v1/workspaces/{$ws->id}/assets")->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/workspaces/{$ws->id}/assets?search=logo")->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/workspaces/{$ws->id}/assets?ids[]={$id}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.ref', "asset:{$id}");

        $viewer = $this->addMember($ws, 'viewer');
        $this->actingAs($viewer)->deleteJson("/api/v1/assets/{$id}")->assertStatus(403);
        $this->actingAs($user)->deleteJson("/api/v1/assets/{$id}")->assertOk();
        $this->getJson("/api/v1/workspaces/{$ws->id}/assets")->assertJsonCount(1, 'data');
    }
}
