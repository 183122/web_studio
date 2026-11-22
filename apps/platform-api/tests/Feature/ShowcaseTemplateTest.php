<?php

namespace Tests\Feature;

use App\Jobs\SendWorkflowEmail;
use App\Models\AppRecord;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** MVP showcase (spec §23): training registration with a real form, records, workflow and admin data. */
class ShowcaseTemplateTest extends TestCase
{
    protected bool $seedTemplates = true;

    public function test_registration_form_creates_record_runs_workflow_and_is_visible_to_admin_only(): void
    {
        Queue::fake();
        [$user, $ws] = $this->tenant();
        $project = $this->project($user, $ws, 'ศูนย์อบรม', 'training-registration');
        $this->actingAs($user);
        $doc = $this->getJson("/api/v1/projects/{$project->id}/schema")->json('data.document');
        $this->assertSame('registrations', $doc['dataModels'][0]['name']);

        $submit = fn (array $data) => $this->withHeader('X-Preview-Role', 'public')->postJson(
            "/api/v1/projects/{$project->id}/preview-runtime/m_registrations/records",
            ['data' => $data, 'meta' => ['hp' => '', 'elapsedMs' => 5000]],
        );
        $submit(['f_reg_name' => 'สมชาย ใจดี', 'f_reg_email' => 'somchai@example.com', 'f_reg_course' => 'การใช้ AI เพื่อการทำงาน'])->assertCreated();
        $submit(['f_reg_name' => 'ซ้ำ', 'f_reg_email' => 'SOMCHAI@example.com', 'f_reg_course' => 'การใช้ AI เพื่อการทำงาน'])
            ->assertStatus(422)->assertJsonPath('error.details.fields.f_reg_email', 'already exists');

        $record = AppRecord::query()->sole();
        $this->assertSame('รอตรวจสอบ', $record->values()['f_reg_status']);
        Queue::assertPushed(SendWorkflowEmail::class, 2);
        Queue::assertPushed(SendWorkflowEmail::class, fn ($j) => $j->to === 'somchai@example.com' && str_contains($j->subject, 'การใช้ AI เพื่อการทำงาน'));

        $this->withHeader('X-Preview-Role', 'public')->getJson("/api/v1/projects/{$project->id}/preview-runtime/m_registrations/records")->assertStatus(403);
        $this->withHeader('X-Preview-Role', 'admin')->getJson("/api/v1/projects/{$project->id}/preview-runtime/m_registrations/records")->assertOk()->assertJsonPath('data.0.data.f_reg_name', 'สมชาย ใจดี');
        $this->getJson("/api/v1/projects/{$project->id}/data/m_registrations/records")->assertOk()->assertJsonPath('meta.total', 1);
    }
}
