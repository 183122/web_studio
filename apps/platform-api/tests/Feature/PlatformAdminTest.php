<?php

namespace Tests\Feature;

use App\Jobs\SendBillingEmail;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

/** Super admin console, owner-approved read-only support access, abuse handling and suspension. */
class PlatformAdminTest extends DataTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([SendBillingEmail::class]);
        [$this->admin] = $this->tenant('Operator');
        $this->artisan('studio:platform-admin', ['email' => $this->admin->email])->assertSuccessful();
        $this->admin->refresh();
    }

    private function adm(string $path): string
    {
        return "/api/v1/admin{$path}";
    }

    public function test_console_is_admin_only_and_changes_require_reason_and_are_audited(): void
    {
        $org = $this->ws->organization;
        $this->actingAs($this->owner)->getJson($this->adm('/organizations'))->assertNotFound();
        $this->actingAs($this->admin)->getJson($this->adm('/organizations?search='.urlencode($this->owner->email)))->assertOk()->assertJsonPath('data.0.id', $org->id);
        $this->actingAs($this->admin)->getJson($this->adm("/organizations/{$org->id}"))->assertOk()->assertJsonPath('data.plan', 'free')->assertJsonPath('data.projects', 1);

        $this->actingAs($this->admin)->postJson($this->adm("/organizations/{$org->id}/plan"), ['plan' => 'pro'])->assertStatus(422);
        $this->actingAs($this->admin)->postJson($this->adm("/organizations/{$org->id}/plan"), ['plan' => 'pro', 'reason' => 'ชดเชยระบบล่ม'])->assertOk()->assertJsonPath('data.plan', 'pro');
        $this->actingAs($this->admin)->postJson($this->adm("/organizations/{$org->id}/credits"), ['credits' => 500, 'reason' => 'โปรโมชันเปิดตัว'])->assertOk()
            ->assertJsonPath('data.usage.ai_credits.limit', 3500);
        $log = AuditLog::query()->where('action', 'admin.plan_changed')->sole();
        $this->assertSame($this->admin->id, $log->actor_id);
        $this->assertSame('ชดเชยระบบล่ม', $log->metadata['reason']);
    }

    public function test_support_access_needs_owner_approval_is_read_only_and_expires(): void
    {
        $org = $this->ws->organization;
        $projectUrl = "/api/v1/projects/{$this->project->id}";
        $this->actingAs($this->admin)->getJson($projectUrl)->assertNotFound(); // no access by default

        $grant = $this->actingAs($this->admin)->postJson($this->adm("/organizations/{$org->id}/support-requests"), ['reason' => 'ลูกค้าแจ้งว่าฟอร์มบันทึกไม่ได้', 'minutes' => 60])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data');
        Queue::assertPushed(SendBillingEmail::class, fn ($j) => $j->to === $this->owner->email);
        $this->actingAs($this->admin)->getJson($projectUrl)->assertNotFound(); // pending is not access

        // Only org owners/admins decide; the platform admin cannot approve their own request.
        $this->actingAs($this->admin)->postJson("/api/v1/organizations/{$org->id}/support-requests/{$grant['id']}", ['decision' => 'approve'])->assertNotFound();
        $this->actingAs($this->owner)->postJson("/api/v1/organizations/{$org->id}/support-requests/{$grant['id']}", ['decision' => 'approve'])->assertOk()->assertJsonPath('data.status', 'approved');

        $this->actingAs($this->admin)->getJson($projectUrl)->assertOk();
        $this->actingAs($this->admin)->getJson("/api/v1/projects/{$this->project->id}/data/m_reg/records")->assertOk();
        $this->actingAs($this->admin)->patchJson($projectUrl, ['name' => 'แก้โดยฝ่ายสนับสนุน'])->assertForbidden(); // read-only
        $this->actingAs($this->admin)->postJson("/api/v1/projects/{$this->project->id}/data/m_reg/records", ['data' => ['f_name' => 'x', 'f_email' => 'x@example.com']])->assertForbidden();
        $this->actingAs($this->admin)->getJson("/api/v1/organizations/{$org->id}/billing")->assertNotFound(); // never billing

        $this->travel(61)->minutes();
        $this->actingAs($this->admin)->getJson($projectUrl)->assertNotFound();
        $this->assertTrue(AuditLog::query()->where('action', 'support.access_approved')->exists());
    }

    public function test_owner_can_deny_or_revoke(): void
    {
        $org = $this->ws->organization;
        $g = $this->actingAs($this->admin)->postJson($this->adm("/organizations/{$org->id}/support-requests"), ['reason' => 'ตรวจสอบปัญหาการเผยแพร่', 'minutes' => 30])->json('data');
        $this->actingAs($this->owner)->postJson("/api/v1/organizations/{$org->id}/support-requests/{$g['id']}", ['decision' => 'deny'])->assertOk()->assertJsonPath('data.status', 'denied');
        $this->actingAs($this->admin)->getJson("/api/v1/projects/{$this->project->id}")->assertNotFound();

        $g = $this->actingAs($this->admin)->postJson($this->adm("/organizations/{$org->id}/support-requests"), ['reason' => 'ตรวจสอบปัญหาการเผยแพร่', 'minutes' => 30])->json('data');
        $this->actingAs($this->owner)->postJson("/api/v1/organizations/{$org->id}/support-requests/{$g['id']}", ['decision' => 'approve'])->assertOk();
        $this->actingAs($this->admin)->getJson("/api/v1/projects/{$this->project->id}")->assertOk();
        $this->actingAs($this->owner)->postJson("/api/v1/organizations/{$org->id}/support-requests/{$g['id']}", ['decision' => 'revoke'])->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->actingAs($this->admin)->getJson("/api/v1/projects/{$this->project->id}")->assertNotFound();
    }

    public function test_abuse_report_leads_to_suspension_that_takes_sites_offline_without_deleting_data(): void
    {
        config(['studio.publishing.require_verified_email' => false]);
        $this->actingAs($this->owner)->putJson("/api/v1/projects/{$this->project->id}/subdomain", ['slug' => 'scam'])->assertOk();
        $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/publish")->assertStatus(202);
        $this->get('http://scam.sites.localhost/')->assertOk();

        $this->postJson('/api/v1/abuse-reports', ['url' => 'http://scam.sites.localhost/login', 'category' => 'phishing', 'details' => 'หน้าเว็บหลอกให้กรอกรหัสธนาคาร'])->assertCreated();
        $report = $this->actingAs($this->admin)->getJson($this->adm('/abuse-reports'))->assertOk()->assertJsonPath('data.0.organizationId', $this->ws->organization_id)->json('data.0');

        $this->actingAs($this->admin)->postJson($this->adm("/organizations/{$this->ws->organization_id}/suspension"), ['suspend' => true, 'reason' => 'ฟิชชิง ตามรายงาน '.$report['id']])->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->actingAs($this->admin)->postJson($this->adm("/abuse-reports/{$report['id']}"), ['status' => 'actioned', 'resolution' => 'ระงับองค์กรแล้ว'])->assertOk();

        $this->get('http://scam.sites.localhost/')->assertNotFound();
        $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/publish")->assertStatus(409)->assertJsonPath('error.code', 'organization_suspended');
        $this->actingAs($this->owner)->getJson("/api/v1/projects/{$this->project->id}/data/m_reg/records")->assertOk(); // owner can still read/export data

        $this->actingAs($this->admin)->postJson($this->adm("/organizations/{$this->ws->organization_id}/suspension"), ['suspend' => false, 'reason' => 'แก้ไขเนื้อหาแล้ว'])->assertOk();
        $this->get('http://scam.sites.localhost/')->assertOk();
    }
}
