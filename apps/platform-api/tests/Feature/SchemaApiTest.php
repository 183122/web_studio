<?php

namespace Tests\Feature;

use App\Models\AppChangeEvent;
use App\Models\AppRevision;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SchemaApiTest extends TestCase
{
    private function setup_project(): array
    {
        [$user, $ws] = $this->tenant();
        $p = $this->project($user, $ws);
        $this->actingAs($user);
        $doc = $this->getJson("/api/v1/projects/{$p->id}/schema")->json('data.document');

        return [$user, $ws, $p, $doc];
    }

    private function heading(string $id, string $text = 'สวัสดี'): array
    {
        return ['id' => $id, 'type' => 'Heading', 'props' => ['text' => $text, 'level' => 'h1'], 'styles' => new \stdClass, 'children' => []];
    }

    public function test_commands_apply_and_increment_revision_with_append_only_events(): void
    {
        [, , $p, $doc] = $this->setup_project();
        $root = $doc['pages'][0]['rootId'];

        $res = $this->postJson("/api/v1/projects/{$p->id}/commands", [
            'baseRevision' => 1,
            'commands' => [
                ['type' => 'component.insert', 'parentId' => $root, 'index' => 0, 'nodes' => [$this->heading('c_hello')]],
                ['type' => 'component.update', 'id' => 'c_hello', 'props' => ['text' => 'ยินดีต้อนรับ'], 'styles' => ['mobile' => ['fontSize' => '24px']]],
            ],
        ])->assertOk();
        $res->assertJsonPath('data.revision', 2)->assertHeader('ETag', '"2"');

        $head = $this->getJson("/api/v1/projects/{$p->id}/schema")->json('data');
        $this->assertSame(2, $head['revision']);
        $this->assertSame('ยินดีต้อนรับ', $head['document']['components']['c_hello']['props']['text']);
        $this->assertSame(['c_hello'], $head['document']['components'][$root]['children']);
        $this->assertSame(2, AppChangeEvent::query()->where('project_id', $p->id)->where('revision', 2)->count());
        // Empty objects survive the round trip as {} (not []).
        $raw = $this->get("/api/v1/projects/{$p->id}/schema")->getContent();
        $this->assertStringContainsString('"props":{}', $raw);
    }

    public function test_stale_base_revision_returns_conflict_and_does_not_write(): void
    {
        [, , $p, $doc] = $this->setup_project();
        $cmd = ['type' => 'site.update', 'changes' => ['name' => 'A']];
        $this->postJson("/api/v1/projects/{$p->id}/commands", ['baseRevision' => 1, 'commands' => [$cmd]])->assertOk();
        $this->postJson("/api/v1/projects/{$p->id}/commands", ['baseRevision' => 1, 'commands' => [['type' => 'site.update', 'changes' => ['name' => 'B']]]])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'revision_conflict')
            ->assertJsonPath('error.details.currentRevision', 2);
        $this->assertSame('A', $this->getJson("/api/v1/projects/{$p->id}/schema")->json('data.document.name'));
    }

    public function test_unsafe_content_is_rejected_atomically(): void
    {
        [, , $p, $doc] = $this->setup_project();
        $root = $doc['pages'][0]['rootId'];
        $res = $this->postJson("/api/v1/projects/{$p->id}/commands", [
            'baseRevision' => 1,
            'commands' => [
                ['type' => 'component.insert', 'parentId' => $root, 'index' => 0, 'nodes' => [$this->heading('c_ok')]],
                ['type' => 'component.insert', 'parentId' => $root, 'index' => 1, 'nodes' => [[
                    'id' => 'c_btn', 'type' => 'Button', 'props' => ['label' => 'x', 'href' => 'javascript:alert(document.cookie)', 'variant' => 'primary', 'newTab' => false], 'styles' => new \stdClass, 'children' => [],
                ]]],
            ],
        ])->assertStatus(422)->assertJsonPath('error.code', 'schema_invalid');
        $this->assertSame('invalid_prop', $res->json('error.details.issues.0.code'));
        $head = $this->getJson("/api/v1/projects/{$p->id}/schema")->json('data');
        $this->assertSame(1, $head['revision']);
        $this->assertArrayNotHasKey('c_ok', $head['document']['components']);
    }

    public function test_malformed_and_unknown_commands_are_rejected(): void
    {
        [, , $p] = $this->setup_project();
        $this->postJson("/api/v1/projects/{$p->id}/commands", ['baseRevision' => 1, 'commands' => [['type' => 'sql.execute', 'query' => 'DROP TABLE users']]])
            ->assertStatus(422)->assertJsonPath('error.details.issues.0.code', 'schema.oneOf');
        $this->postJson("/api/v1/projects/{$p->id}/commands", ['baseRevision' => 1, 'commands' => []])
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid_batch');
        $this->postJson("/api/v1/projects/{$p->id}/commands", ['commands' => []])
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid_body');
        $this->postJson("/api/v1/projects/{$p->id}/commands", ['baseRevision' => 1, 'commands' => [['type' => 'component.remove', 'id' => 'c_missing']]])
            ->assertStatus(422)->assertJsonPath('error.code', 'command_rejected')->assertJsonPath('error.details.reason', 'not_found');
    }

    public function test_put_requires_if_match_and_validates_project_binding(): void
    {
        [, , $p, $doc] = $this->setup_project();
        $doc['name'] = 'แทนที่ทั้งเอกสาร';
        $this->putJson("/api/v1/projects/{$p->id}/schema", ['document' => $doc])->assertStatus(428);
        $this->putJson("/api/v1/projects/{$p->id}/schema", ['document' => $doc], ['If-Match' => '"7"'])->assertStatus(409);
        $doc['projectId'] = 'someone-else';
        $this->putJson("/api/v1/projects/{$p->id}/schema", ['document' => $doc, 'message' => 'import'], ['If-Match' => '"1"'])
            ->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.checkpoint', true);
        // projectId is always re-bound to the URL's project (no cross-project writes via body).
        $this->assertSame($p->id, $this->getJson("/api/v1/projects/{$p->id}/schema")->json('data.document.projectId'));
    }

    public function test_checkpoints_are_written_by_interval_and_restore_creates_new_revision(): void
    {
        [, , $p] = $this->setup_project();
        $set = fn (int $base, string $name, ?string $message = null) => $this->postJson("/api/v1/projects/{$p->id}/commands", array_filter([
            'baseRevision' => $base, 'commands' => [['type' => 'site.update', 'changes' => ['name' => $name]]], 'message' => $message,
        ]))->assertOk();

        $set(1, 'สอง');                       // within interval → no checkpoint
        $this->assertFalse($set(2, 'สาม')->json('data.checkpoint'));
        $this->assertTrue($set(3, 'สี่', 'เวอร์ชันพร้อมรีวิว')->json('data.checkpoint')); // named version
        Carbon::setTestNow(now()->addMinutes(10));
        $this->assertTrue($set(4, 'ห้า')->json('data.checkpoint'));   // interval elapsed
        Carbon::setTestNow();

        $list = $this->getJson("/api/v1/projects/{$p->id}/revisions")->assertOk()->json('data');
        $this->assertSame([5, 4, 1], array_column($list, 'revision'));
        $this->assertSame('เวอร์ชันพร้อมรีวิว', $list[1]['message']);

        $this->getJson("/api/v1/projects/{$p->id}/revisions/4")->assertOk()->assertJsonPath('data.document.name', 'สี่');
        $this->postJson("/api/v1/projects/{$p->id}/revisions/4/restore", ['baseRevision' => 4])->assertStatus(409);
        $this->postJson("/api/v1/projects/{$p->id}/revisions/4/restore", ['baseRevision' => 5])->assertOk()->assertJsonPath('data.revision', 6);
        $this->assertSame('สี่', $this->getJson("/api/v1/projects/{$p->id}/schema")->json('data.document.name'));
        $this->assertSame(4, AppRevision::query()->where('project_id', $p->id)->where('revision', 6)->value('restored_from_revision'));
        $this->postJson("/api/v1/projects/{$p->id}/revisions/3/restore", ['baseRevision' => 6])->assertNotFound()->assertJsonPath('error.code', 'revision_not_found');
    }

    public function test_idempotency_key_replays_instead_of_double_applying(): void
    {
        [, , $p, $doc] = $this->setup_project();
        $root = $doc['pages'][0]['rootId'];
        $body = ['baseRevision' => 1, 'commands' => [['type' => 'component.insert', 'parentId' => $root, 'index' => 0, 'nodes' => [$this->heading('c_once')]]]];
        $first = $this->postJson("/api/v1/projects/{$p->id}/commands", $body, ['Idempotency-Key' => 'save-abc12345'])->assertOk();
        $second = $this->postJson("/api/v1/projects/{$p->id}/commands", $body, ['Idempotency-Key' => 'save-abc12345'])->assertOk();
        $second->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('data.revision'), $second->json('data.revision'));
        $body['commands'][0]['nodes'][0]['id'] = 'c_other';
        $this->postJson("/api/v1/projects/{$p->id}/commands", $body, ['Idempotency-Key' => 'save-abc12345'])
            ->assertStatus(422)->assertJsonPath('error.code', 'idempotency_key_reused');
    }

    public function test_viewer_can_read_but_not_write(): void
    {
        [$owner, $ws, $p] = $this->setup_project();
        $viewer = $this->addMember($ws, 'viewer');
        $this->actingAs($viewer)->getJson("/api/v1/projects/{$p->id}/schema")->assertOk();
        $this->actingAs($viewer)->postJson("/api/v1/projects/{$p->id}/commands", ['baseRevision' => 1, 'commands' => [['type' => 'site.update', 'changes' => ['name' => 'x']]]])
            ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
        $this->actingAs($viewer)->deleteJson("/api/v1/projects/{$p->id}")->assertStatus(403);
        $this->actingAs($viewer)->postJson("/api/v1/workspaces/{$ws->id}/projects", ['name' => 'x'])->assertStatus(403);
    }
}
