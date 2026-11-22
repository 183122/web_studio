<?php

namespace Tests\Feature;

use App\Models\AppAuditLog;
use App\Models\AppRecord;

class DataRecordsTest extends DataTestCase
{
    public function test_crud_with_validation_and_audit(): void
    {
        $this->actingAs($this->owner);
        $r = $this->createRecord(['f_name' => 'สมชาย ใจดี', 'f_email' => 'Somchai@Example.com', 'f_seats' => '2', 'f_vip' => 'true']);
        $this->assertSame('somchai@example.com', $r['data']['f_email']);
        $this->assertSame(2, $r['data']['f_seats']);
        $this->assertTrue($r['data']['f_vip']);
        $this->assertNull($r['data']['f_status']);
        $this->assertSame(1, $r['version']);

        $this->postJson($this->records(), ['data' => ['f_name' => 'x', 'f_email' => 'bad', 'f_seats' => 0, 'f_status' => 'hacked', 'f_unknown' => 1]])
            ->assertStatus(422)->assertJsonPath('error.code', 'record_invalid')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['f_email', 'f_seats', 'f_status', 'f_unknown']]]]);

        $u = $this->patchJson($this->records("/{$r['id']}"), ['data' => ['f_status' => 'confirmed'], 'version' => 1])->assertOk();
        $u->assertJsonPath('data.version', 2)->assertJsonPath('data.data.f_status', 'confirmed')->assertJsonPath('data.data.f_name', 'สมชาย ใจดี');
        $this->patchJson($this->records("/{$r['id']}"), ['data' => ['f_status' => 'cancelled'], 'version' => 1])
            ->assertStatus(409)->assertJsonPath('error.code', 'record_conflict')->assertJsonPath('error.details.currentVersion', 2);

        $this->deleteJson($this->records("/{$r['id']}"))->assertOk();
        $this->getJson($this->records("/{$r['id']}"))->assertNotFound();
        $this->assertSame(['record.created', 'record.updated', 'record.deleted'], AppAuditLog::query()->where('record_id', $r['id'])->orderBy('id')->pluck('action')->all());
    }

    public function test_unique_fields_are_enforced_case_insensitively_and_released_on_delete(): void
    {
        $this->actingAs($this->owner);
        $a = $this->createRecord(['f_name' => 'A', 'f_email' => 'dup@example.com']);
        $this->postJson($this->records(), ['data' => ['f_name' => 'B', 'f_email' => 'DUP@example.com']])
            ->assertStatus(422)->assertJsonPath('error.details.fields.f_email', 'already exists');
        $this->deleteJson($this->records("/{$a['id']}"))->assertOk();
        $this->createRecord(['f_name' => 'B', 'f_email' => 'dup@example.com']);
    }

    public function test_list_search_filter_sort_and_pagination(): void
    {
        $this->actingAs($this->owner);
        foreach ([['สมชาย', 10, 'pending'], ['สมหญิง', 9, 'confirmed'], ['John', 100, 'confirmed'], ['ปิติ', 1, 'pending']] as $i => [$n, $seats, $status]) {
            $this->createRecord(['f_name' => $n, 'f_email' => "u{$i}@example.com", 'f_seats' => $seats, 'f_status' => $status]);
        }
        $this->getJson($this->records().'?search='.urlencode('สม'))->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2);
        $this->getJson($this->records().'?filter[f_status]=confirmed')->assertJsonCount(2, 'data');
        $this->getJson($this->records().'?filter[f_seats]=9')->assertJsonCount(1, 'data')->assertJsonPath('data.0.data.f_name', 'สมหญิง');
        $seats = array_column(array_column($this->getJson($this->records().'?sort=f_seats&dir=asc')->json('data'), 'data'), 'f_seats');
        $this->assertSame([1, 9, 10, 100], $seats); // numeric, not lexicographic
        $p2 = $this->getJson($this->records().'?perPage=3&page=2')->assertOk();
        $p2->assertJsonCount(1, 'data')->assertJsonPath('meta.lastPage', 2)->assertJsonPath('meta.total', 4);
        $this->postJson($this->records('/bulk-delete'), ['ids' => array_column($this->getJson($this->records().'?filter[f_status]=pending')->json('data'), 'id')])
            ->assertOk()->assertJsonPath('data.deleted', 2);
        $this->getJson($this->records())->assertJsonPath('meta.total', 2);
    }

    public function test_viewer_reads_only_and_other_tenants_get_404(): void
    {
        $r = $this->createRecord(['f_name' => 'A', 'f_email' => 'a@example.com']);
        $viewer = $this->addMember($this->ws, 'viewer');
        $this->actingAs($viewer)->getJson($this->records())->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($viewer)->postJson($this->records(), ['data' => ['f_name' => 'x', 'f_email' => 'x@example.com']])->assertStatus(403);
        $this->actingAs($viewer)->deleteJson($this->records("/{$r['id']}"))->assertStatus(403);

        [$intruder] = $this->tenant('Intruder');
        foreach (['GET' => $this->records(), 'POST' => $this->records()] as $method => $uri) {
            $this->actingAs($intruder)->json($method, $uri, ['data' => []])->assertNotFound();
        }
        $this->actingAs($intruder)->getJson($this->records("/{$r['id']}"))->assertNotFound();
        $this->actingAs($intruder)->getJson("/api/v1/projects/{$this->project->id}/data/m_reg/export")->assertNotFound();
    }

    public function test_unknown_model_is_404_and_removed_field_values_are_retained(): void
    {
        $this->actingAs($this->owner);
        $this->getJson("/api/v1/projects/{$this->project->id}/data/m_none/records")->assertNotFound();
        $r = $this->createRecord(['f_name' => 'A', 'f_email' => 'a@example.com', 'f_seats' => 3]);
        $model = self::MODEL;
        $model['fields'] = array_values(array_filter($model['fields'], fn ($f) => $f['id'] !== 'f_seats'));
        $this->applyCommands([['type' => 'model.upsert', 'index' => 0, 'model' => $model]]);
        $this->getJson($this->records("/{$r['id']}"))->assertOk()->assertJsonMissingPath('data.data.f_seats');
        $this->assertSame(3, AppRecord::query()->find($r['id'])->values()['f_seats']); // not destroyed
    }

    public function test_record_quota_from_plan(): void
    {
        $plan = $this->ws->organization->plan;
        $plan->update(['entitlements' => array_merge($plan->entitlements, ['max_records' => 1])]);
        $this->createRecord(['f_name' => 'A', 'f_email' => 'a@example.com']);
        $this->actingAs($this->owner)->postJson($this->records(), ['data' => ['f_name' => 'B', 'f_email' => 'b@example.com']])
            ->assertStatus(422)->assertJsonPath('error.code', 'quota_exceeded');
    }
}
