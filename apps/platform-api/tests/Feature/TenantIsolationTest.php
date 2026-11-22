<?php

namespace Tests\Feature;

use App\Models\Asset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * J8 (Phase 1 scope): cross-tenant access through IDs, files and API must be blocked
 * and must not disclose existence (404).
 */
class TenantIsolationTest extends TestCase
{
    public function test_other_tenant_cannot_reach_projects_schema_or_revisions(): void
    {
        [$alice, $wsA] = $this->tenant('Alice');
        [$bob, $wsB] = $this->tenant('Bob');
        $p = $this->project($alice, $wsA);
        $this->actingAs($bob);

        $routes = [
            ['GET', "/api/v1/projects/{$p->id}"],
            ['PATCH', "/api/v1/projects/{$p->id}"],
            ['DELETE', "/api/v1/projects/{$p->id}"],
            ['POST', "/api/v1/projects/{$p->id}/duplicate"],
            ['POST', "/api/v1/projects/{$p->id}/archive"],
            ['PUT', "/api/v1/projects/{$p->id}/favorite"],
            ['GET', "/api/v1/projects/{$p->id}/schema"],
            ['PUT', "/api/v1/projects/{$p->id}/schema"],
            ['POST', "/api/v1/projects/{$p->id}/commands"],
            ['GET', "/api/v1/projects/{$p->id}/revisions"],
            ['GET', "/api/v1/projects/{$p->id}/revisions/1"],
            ['POST', "/api/v1/projects/{$p->id}/revisions/1/restore"],
            ['GET', "/api/v1/workspaces/{$wsA->id}"],
            ['GET', "/api/v1/workspaces/{$wsA->id}/projects"],
            ['POST', "/api/v1/workspaces/{$wsA->id}/projects"],
            ['GET', "/api/v1/workspaces/{$wsA->id}/members"],
            ['GET', "/api/v1/workspaces/{$wsA->id}/assets"],
            ['GET', "/api/v1/workspaces/{$wsA->id}/audit-logs"],
        ];
        foreach ($routes as [$method, $uri]) {
            $this->json($method, $uri, ['name' => 'x', 'baseRevision' => 1, 'commands' => [['type' => 'site.update', 'changes' => ['name' => 'pwned']]]], ['If-Match' => '1'])
                ->assertStatus(404, "{$method} {$uri}");
        }
        // Bob's own listing never includes Alice's project.
        $this->getJson("/api/v1/workspaces/{$wsB->id}/projects?status=all")->assertOk()->assertJsonCount(0, 'data');
        $this->assertNotSame('pwned', $p->fresh()->name);
    }

    public function test_assets_cannot_be_referenced_or_deleted_across_tenants(): void
    {
        Storage::fake('assets');
        [$alice, $wsA] = $this->tenant('Alice');
        [$bob, $wsB] = $this->tenant('Bob');
        $assetId = $this->actingAs($alice)->post("/api/v1/workspaces/{$wsA->id}/assets", ['file' => UploadedFile::fake()->image('a.png', 20, 20)])
            ->assertCreated()->json('data.id');

        $p = $this->project($bob, $wsB);
        $doc = $this->actingAs($bob)->getJson("/api/v1/projects/{$p->id}/schema")->json('data.document');
        $root = $doc['pages'][0]['rootId'];
        $this->postJson("/api/v1/projects/{$p->id}/commands", [
            'baseRevision' => 1,
            'commands' => [['type' => 'component.insert', 'parentId' => $root, 'index' => 0, 'nodes' => [[
                'id' => 'c_img', 'type' => 'Image', 'props' => ['src' => "asset:{$assetId}", 'alt' => ''], 'styles' => new \stdClass, 'children' => [],
            ]]]],
        ])->assertStatus(422)->assertJsonPath('error.details.issues.0.code', 'asset_not_found');

        $this->deleteJson("/api/v1/assets/{$assetId}")->assertNotFound();
        $this->getJson("/api/v1/workspaces/{$wsB->id}/assets?ids[]={$assetId}")->assertOk()->assertJsonCount(0, 'data');
        $this->assertNull(Asset::query()->find($assetId)->deleted_at);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        [$alice, $wsA] = $this->tenant('Alice');
        $p = $this->project($alice, $wsA);
        $this->getJson("/api/v1/projects/{$p->id}/schema")->assertStatus(401);
        $this->getJson("/api/v1/workspaces/{$wsA->id}/projects")->assertStatus(401);
    }
}
