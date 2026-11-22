<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Plan;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    protected bool $seedTemplates = true;

    public function test_create_blank_and_from_template(): void
    {
        [$user, $ws] = $this->tenant();
        $this->actingAs($user);

        $blank = $this->postJson("/api/v1/workspaces/{$ws->id}/projects", ['name' => 'เว็บใหม่'])->assertCreated();
        $blank->assertJsonPath('data.revision', 1)->assertJsonPath('data.status', 'active');
        $schema = $this->getJson("/api/v1/projects/{$blank->json('data.id')}/schema")->assertOk();
        $schema->assertJsonPath('data.document.projectId', $blank->json('data.id'))
            ->assertJsonPath('data.document.pages.0.path', '/')
            ->assertHeader('ETag', '"1"');

        $tpl = $this->postJson("/api/v1/workspaces/{$ws->id}/projects", ['name' => 'ระบบลงทะเบียนอบรม', 'templateSlug' => 'training-registration'])->assertCreated();
        $tpl->assertJsonPath('data.template.slug', 'training-registration');
        $doc = $this->getJson("/api/v1/projects/{$tpl->json('data.id')}/schema")->json('data.document');
        $this->assertCount(4, $doc['pages']);
        $this->assertSame('ระบบลงทะเบียนอบรม', $doc['name']);

        $this->postJson("/api/v1/workspaces/{$ws->id}/projects", ['name' => 'x', 'templateSlug' => 'missing'])->assertStatus(422);
        $this->assertSame(2, AuditLog::query()->where('action', 'project.created')->count());
    }

    public function test_project_quota_is_enforced_from_plan_entitlements(): void
    {
        [$user, $ws] = $this->tenant();
        $this->actingAs($user);
        for ($i = 1; $i <= 3; $i++) {
            $this->postJson("/api/v1/workspaces/{$ws->id}/projects", ['name' => "P{$i}"])->assertCreated();
        }
        $this->postJson("/api/v1/workspaces/{$ws->id}/projects", ['name' => 'P4'])
            ->assertStatus(422)->assertJsonPath('error.code', 'quota_exceeded')->assertJsonPath('error.details.limit', 3);

        // Upgrading the plan (data-driven entitlements) lifts the limit.
        $ws->organization->update(['plan_id' => Plan::query()->where('code', 'pro')->value('id')]);
        $this->postJson("/api/v1/workspaces/{$ws->id}/projects", ['name' => 'P4'])->assertCreated();
    }

    public function test_list_search_filter_sort_and_favorites(): void
    {
        [$user, $ws] = $this->tenant();
        $ws->organization->update(['plan_id' => Plan::query()->where('code', 'pro')->value('id')]);
        $this->actingAs($user);
        $a = $this->project($user, $ws, 'ร้านกาแฟ');
        $b = $this->project($user, $ws, 'โรงเรียนอนุบาล');
        $c = $this->project($user, $ws, 'คลินิก 100%');

        $this->getJson("/api/v1/workspaces/{$ws->id}/projects")->assertOk()->assertJsonCount(3, 'data');
        $this->getJson("/api/v1/workspaces/{$ws->id}/projects?search=".urlencode('กาแฟ'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->id);
        $this->getJson("/api/v1/workspaces/{$ws->id}/projects?search=".urlencode('%'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $c->id);

        $this->putJson("/api/v1/projects/{$b->id}/favorite")->assertOk();
        $this->getJson("/api/v1/workspaces/{$ws->id}/projects?favorites=1")->assertJsonCount(1, 'data')->assertJsonPath('data.0.isFavorite', true);
        $this->deleteJson("/api/v1/projects/{$b->id}/favorite")->assertOk();
        $this->getJson("/api/v1/workspaces/{$ws->id}/projects?favorites=1")->assertJsonCount(0, 'data');

        $this->postJson("/api/v1/projects/{$a->id}/archive")->assertOk()->assertJsonPath('data.status', 'archived');
        $this->getJson("/api/v1/workspaces/{$ws->id}/projects")->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/workspaces/{$ws->id}/projects?status=archived")->assertJsonCount(1, 'data');
        $names = array_column($this->getJson("/api/v1/workspaces/{$ws->id}/projects?status=all&sort=name")->json('data'), 'name');
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);
    }

    public function test_archived_project_cannot_be_edited_until_unarchived(): void
    {
        [$user, $ws] = $this->tenant();
        $p = $this->project($user, $ws);
        $this->actingAs($user);
        $this->postJson("/api/v1/projects/{$p->id}/archive")->assertOk();
        $cmd = ['baseRevision' => 1, 'commands' => [['type' => 'site.update', 'changes' => ['name' => 'x']]]];
        $this->postJson("/api/v1/projects/{$p->id}/commands", $cmd)->assertStatus(403);
        $this->postJson("/api/v1/projects/{$p->id}/unarchive")->assertOk();
        $this->postJson("/api/v1/projects/{$p->id}/commands", $cmd)->assertOk();
    }

    public function test_duplicate_trash_and_restore(): void
    {
        [$user, $ws] = $this->tenant();
        $p = $this->project($user, $ws, 'ต้นฉบับ', 'business-landing');
        $this->actingAs($user);

        $copy = $this->postJson("/api/v1/projects/{$p->id}/duplicate")->assertCreated();
        $copy->assertJsonPath('data.name', 'ต้นฉบับ (สำเนา)');
        $copyDoc = $this->getJson("/api/v1/projects/{$copy->json('data.id')}/schema")->json('data.document');
        $origDoc = $this->getJson("/api/v1/projects/{$p->id}/schema")->json('data.document');
        $this->assertSame($copy->json('data.id'), $copyDoc['projectId']);
        $this->assertSame($origDoc['components'], $copyDoc['components']);

        $this->deleteJson("/api/v1/projects/{$p->id}")->assertOk();
        $this->getJson("/api/v1/projects/{$p->id}")->assertNotFound();
        $this->getJson("/api/v1/workspaces/{$ws->id}/projects?status=trashed")->assertJsonCount(1, 'data')->assertJsonPath('data.0.isTrashed', true);
        $this->postJson("/api/v1/projects/{$p->id}/restore")->assertOk()->assertJsonPath('data.isTrashed', false);
        $this->getJson("/api/v1/projects/{$p->id}")->assertOk();
    }

    public function test_rename_and_audit_log_access(): void
    {
        [$user, $ws] = $this->tenant();
        $p = $this->project($user, $ws);
        $editor = $this->addMember($ws, 'editor');

        $this->actingAs($editor)->patchJson("/api/v1/projects/{$p->id}", ['name' => 'ชื่อใหม่'])->assertOk()->assertJsonPath('data.name', 'ชื่อใหม่');
        $this->actingAs($editor)->getJson("/api/v1/workspaces/{$ws->id}/audit-logs")->assertStatus(403);
        $logs = $this->actingAs($user)->getJson("/api/v1/workspaces/{$ws->id}/audit-logs")->assertOk()->json('data');
        $this->assertContains('project.updated', array_column($logs, 'action'));
    }

    public function test_workspace_summary_reports_usage(): void
    {
        [$user, $ws] = $this->tenant();
        $this->project($user, $ws);
        $this->actingAs($user)->getJson("/api/v1/workspaces/{$ws->id}")->assertOk()
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonPath('data.usage.projects.used', 1)
            ->assertJsonPath('data.usage.projects.limit', 3);
    }

    public function test_templates_are_listed_with_documents(): void
    {
        $this->getJson('/api/v1/templates')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.slug', 'training-registration');
        $this->getJson('/api/v1/templates/school-website')->assertOk()->assertJsonPath('data.document.seo.lang', 'th');
        $this->getJson('/api/v1/templates/nope')->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }
}
