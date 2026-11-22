<?php

namespace Tests\Feature;

use App\Domain\Data\DataContext;
use App\Domain\Data\WorkflowEngine;
use App\Jobs\SendWorkflowEmail;
use App\Models\AppRecord;
use App\Models\WorkflowRun;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;

class DataRuntimeTest extends DataTestCase
{
    private function runtime(string $role, array $data, array $meta = ['hp' => '', 'elapsedMs' => 4000])
    {
        return $this->actingAs($this->owner)->withHeader('X-Preview-Role', $role)
            ->postJson("/api/v1/projects/{$this->project->id}/preview-runtime/m_reg/records", ['data' => $data, 'meta' => $meta]);
    }

    public function test_preview_runtime_enforces_app_permissions_per_role(): void
    {
        $this->runtime('public', ['f_name' => 'ผู้เยี่ยมชม', 'f_email' => 'guest@example.com'])->assertCreated();
        $list = fn (string $role) => $this->actingAs($this->owner)->withHeader('X-Preview-Role', $role)->getJson("/api/v1/projects/{$this->project->id}/preview-runtime/m_reg/records");
        $list('public')->assertStatus(403)->assertJsonPath('error.code', 'app_permission_denied');
        $list('member')->assertOk()->assertJsonCount(1, 'data');
        $this->runtime('member', ['f_name' => 'x', 'f_email' => 'm@example.com'])->assertStatus(403);
        $this->runtime('root', ['f_name' => 'x', 'f_email' => 'm@example.com'])->assertStatus(422);
        $this->assertSame('form', AppRecord::query()->first()->source);
    }

    public function test_form_spam_controls(): void
    {
        $this->runtime('public', ['f_name' => 'bot', 'f_email' => 'bot@example.com'], ['hp' => 'http://spam', 'elapsedMs' => 5000])->assertStatus(422);
        $this->runtime('public', ['f_name' => 'bot', 'f_email' => 'bot@example.com'], ['hp' => '', 'elapsedMs' => 200])->assertStatus(422);
        $this->assertSame(0, AppRecord::query()->count());
        // The limiter (10/min per IP+project) also counts the two rejected attempts.
        for ($i = 0; $i < 8; $i++) {
            $this->runtime('public', ['f_name' => "u{$i}", 'f_email' => "u{$i}@example.com"])->assertCreated();
        }
        $this->runtime('public', ['f_name' => 'u10', 'f_email' => 'u10@example.com'])->assertStatus(429);
    }

    private function addWelcomeWorkflow(): void
    {
        $this->applyCommands([['type' => 'workflow.upsert', 'index' => 0, 'workflow' => [
            'id' => 'w_welcome', 'name' => 'ยืนยัน', 'enabled' => true,
            'trigger' => ['event' => 'record.created', 'modelId' => 'm_reg'],
            'conditions' => [['fieldId' => 'f_email', 'op' => 'not_empty']],
            'actions' => [
                ['type' => 'record.setField', 'fieldId' => 'f_status', 'value' => 'pending'],
                ['type' => 'email.send', 'to' => 'field:f_email', 'subject' => 'สวัสดี {{full_name}}', 'body' => "เรียน {{ full_name }}\nได้รับใบสมัครแล้ว"],
            ],
        ]], ['type' => 'workflow.upsert', 'index' => 1, 'workflow' => [
            'id' => 'w_vip', 'name' => 'VIP', 'enabled' => true,
            'trigger' => ['event' => 'record.updated', 'modelId' => 'm_reg'],
            'conditions' => [['fieldId' => 'f_seats', 'op' => 'gt', 'value' => 5]],
            'actions' => [['type' => 'record.setField', 'fieldId' => 'f_vip', 'value' => true]],
        ]]]);
    }

    public function test_workflows_run_once_with_queued_email_and_no_recursion(): void
    {
        Queue::fake();
        $this->addWelcomeWorkflow();
        $r = $this->createRecord(['f_name' => 'สมชาย', 'f_email' => 'somchai@example.com']);

        $fresh = AppRecord::query()->find($r['id']);
        $this->assertSame('pending', $fresh->values()['f_status']);
        $this->assertSame(2, $fresh->version);
        Queue::assertPushed(SendWorkflowEmail::class, fn ($job) => $job->to === 'somchai@example.com' && $job->subject === 'สวัสดี สมชาย' && str_contains($job->body, "เรียน สมชาย\n"));
        $run = WorkflowRun::query()->where('workflow_id', 'w_welcome')->sole();
        $this->assertSame('succeeded', $run->status);
        // The workflow's own field update did not trigger record.updated workflows.
        $this->assertSame(0, WorkflowRun::query()->where('workflow_id', 'w_vip')->count());

        // Idempotency: re-dispatching the same event/version does not run again.
        app(WorkflowEngine::class)->dispatch(DataContext::forProject($this->project), AppRecord::query()->find($r['id'])->forceFill(['version' => 1]), 'record.created');
        $this->assertSame(1, WorkflowRun::query()->where('workflow_id', 'w_welcome')->count());

        // record.updated with a non-matching condition is recorded as skipped; a matching one applies.
        $this->patchJson($this->records("/{$r['id']}"), ['data' => ['f_seats' => 2], 'version' => 2])->assertOk();
        $this->assertSame('skipped', WorkflowRun::query()->where('workflow_id', 'w_vip')->latest('id')->value('status'));
        $this->patchJson($this->records("/{$r['id']}"), ['data' => ['f_seats' => 8], 'version' => 3])->assertOk();
        $this->assertTrue(AppRecord::query()->find($r['id'])->values()['f_vip']);

        $runs = $this->getJson("/api/v1/projects/{$this->project->id}/workflow-runs")->assertOk()->json('data');
        $this->assertCount(3, $runs);
        $this->assertStringContainsString('***@example.com', json_encode($runs[2]['steps']));
    }

    public function test_workflow_dry_run_has_no_side_effects(): void
    {
        Queue::fake();
        $this->addWelcomeWorkflow();
        $res = $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/workflows/w_welcome/dry-run", ['data' => ['f_name' => 'ทดลอง', 'f_email' => 'try@example.com']])->assertOk();
        $res->assertJsonPath('data.matched', true)->assertJsonPath('data.actions.1.subject', 'สวัสดี ทดลอง');
        $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/workflows/w_welcome/dry-run", ['data' => []])->assertJsonPath('data.matched', false);
        Queue::assertNothingPushed();
        $this->assertSame(0, WorkflowRun::query()->count());
        $this->assertSame(0, AppRecord::query()->count());
    }

    public function test_csv_export_neutralises_formulas_and_import_is_all_or_nothing(): void
    {
        $this->createRecord(['f_name' => '=HYPERLINK("http://evil")', 'f_email' => 'a@example.com', 'f_vip' => true]);
        $csv = $this->actingAs($this->owner)->get("/api/v1/projects/{$this->project->id}/data/m_reg/export")->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFid,full_name,email,seats,status,vip,created_at,updated_at", $csv);
        $this->assertStringContainsString("\"'=HYPERLINK(\"\"http://evil\"\")\"", $csv);

        $bad = UploadedFile::fake()->createWithContent('x.csv', "full_name,email,seats\nสมชาย,s@example.com,2\nสมหญิง,not-email,3\n");
        $this->post("/api/v1/projects/{$this->project->id}/data/m_reg/import", ['file' => $bad])->assertStatus(422)
            ->assertJsonPath('data.errors.0.row', 3)->assertJsonPath('data.errors.0.field', 'f_email');
        $this->assertSame(1, AppRecord::query()->count());

        $good = UploadedFile::fake()->createWithContent('y.csv', "\xEF\xBB\xBFชื่อ,email,status\nสมชาย,s@example.com,pending\nสมหญิง,h@example.com,\n");
        $this->post("/api/v1/projects/{$this->project->id}/data/m_reg/import", ['file' => $good, 'dryRun' => '1'])->assertOk()->assertJsonPath('data.created', 0)->assertJsonPath('data.rows', 2);
        $this->post("/api/v1/projects/{$this->project->id}/data/m_reg/import", ['file' => $good])->assertOk()->assertJsonPath('data.created', 2);
        $this->assertSame(3, AppRecord::query()->count());

        $dupe = UploadedFile::fake()->createWithContent('z.csv', "full_name,email\nX,x@example.com\nY,s@example.com\n");
        $this->post("/api/v1/projects/{$this->project->id}/data/m_reg/import", ['file' => $dupe])->assertStatus(422);
        $this->assertSame(3, AppRecord::query()->count()); // rolled back, X was not kept

        $unknown = UploadedFile::fake()->createWithContent('u.csv', "full_name,password\nX,y\n");
        $this->post("/api/v1/projects/{$this->project->id}/data/m_reg/import", ['file' => $unknown])->assertStatus(422)->assertJsonPath('error.code', 'csv_unknown_columns');
    }

    public function test_migration_impact_preview(): void
    {
        $this->createRecord(['f_name' => 'A', 'f_email' => 'a@example.com', 'f_seats' => 3, 'f_status' => 'pending']);
        $this->createRecord(['f_name' => 'B', 'f_email' => 'b@example.com', 'f_status' => 'pending']);
        $model = self::MODEL;
        $model['fields'][2] = ['id' => 'f_seats', 'name' => 'seats', 'label' => 'ที่นั่ง', 'type' => 'integer', 'required' => true, 'unique' => false]; // now required
        $model['fields'][3]['unique'] = true; // duplicates exist
        unset($model['fields'][4]); // remove vip (no data)
        $model['fields'] = array_values($model['fields']);
        $res = $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/data/impact", ['model' => $model])->assertOk();
        $res->assertJsonPath('data.totalRecords', 2)->assertJsonPath('data.requiresConfirmation', true);
        $fields = collect($res->json('data.fields'))->keyBy('fieldId');
        $this->assertSame(1, $fields['f_seats']['invalidRecords']);
        $this->assertSame(1, $fields['f_status']['duplicateValues']);
        $this->assertSame('removed', $fields['f_vip']['change']);
        $this->assertSame(0, $fields['f_vip']['recordsWithData']);
        $this->postJson("/api/v1/projects/{$this->project->id}/data/impact", ['model' => ['id' => 'x']])->assertStatus(422);
    }
}
