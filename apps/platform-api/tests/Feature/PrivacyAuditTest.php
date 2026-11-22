<?php

namespace Tests\Feature;

use App\Models\AppRecord;
use App\Models\AppUser;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/** PDPA (consent, export, erasure, retention) and the tamper-evident audit chain. */
class PrivacyAuditTest extends DataTestCase
{
    public function test_registration_records_consent_and_marketing_is_opt_in(): void
    {
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/register', ['name' => 'ก', 'email' => 'consent@example.com', 'password' => 'StrongPass123', 'password_confirmation' => 'StrongPass123', 'acceptTerms' => true])->assertCreated();
        $u = User::query()->where('email', 'consent@example.com')->sole();
        $this->assertSame(config('studio.legal.terms_version'), $u->terms_version);
        $this->assertNotNull($u->consented_at);
        $this->assertNull($u->marketing_consent_at);
        $this->actingAs($u)->putJson('/api/v1/auth/privacy/consent', ['marketing' => true])->assertOk()->assertJsonPath('data.marketing', true);
    }

    public function test_export_contains_personal_data_and_is_audited(): void
    {
        $res = $this->actingAs($this->owner)->get('/api/v1/auth/privacy/export')->assertOk();
        $this->assertStringContainsString('attachment;', $res->headers->get('Content-Disposition'));
        $data = $res->json();
        $this->assertSame($this->owner->email, $data['profile']['email']);
        $this->assertNotEmpty($data['organizations']);
        $this->assertArrayNotHasKey('password', $data['profile']);
        $this->assertTrue(AuditLog::query()->where('action', 'privacy.data_exported')->exists());
    }

    public function test_account_deletion_anonymizes_and_schedules_owned_org_purge(): void
    {
        $orgId = $this->ws->organization_id;
        $member = User::factory()->create();
        $this->ws->organization->members()->attach($member->id, ['role' => 'member']);
        $this->actingAs($this->owner)->postJson('/api/v1/auth/privacy/delete-account', ['password' => 'Password12345', 'confirm' => 'DELETE'])
            ->assertStatus(422)->assertJsonPath('error.code', 'transfer_ownership_first');
        $this->ws->organization->members()->detach($member->id);
        $this->actingAs($this->owner)->postJson('/api/v1/auth/privacy/delete-account', ['password' => 'wrong', 'confirm' => 'DELETE'])->assertStatus(422);
        $this->actingAs($this->owner)->postJson('/api/v1/auth/privacy/delete-account', ['password' => 'Password12345', 'confirm' => 'DELETE'])->assertOk();

        $u = User::query()->find($this->owner->id);
        $this->assertStringEndsWith('@deleted.invalid', $u->email);
        $this->assertSame('ผู้ใช้ที่ลบแล้ว', $u->name);
        $this->assertNull(Organization::query()->find($orgId)); // soft deleted
        $this->assertNotNull(Organization::withTrashed()->find($orgId));
        $this->postJson('/api/v1/auth/login', ['email' => $this->owner->email, 'password' => 'Password12345'])->assertStatus(422);

        // After the grace period the organization and its data are purged; the audit chain stays intact.
        $this->travel(31)->days();
        $this->artisan('studio:retention:run')->assertSuccessful();
        $this->assertNull(Organization::withTrashed()->find($orgId));
        $this->assertSame(0, Project::query()->where('organization_id', $orgId)->count());
        $this->assertNull(AuditLogger::verify());
    }

    public function test_app_owner_can_export_and_erase_an_end_user(): void
    {
        $u = AppUser::create(['project_id' => $this->project->id, 'environment' => 'production', 'email' => 'enduser@example.com', 'name' => 'ปลายทาง', 'password' => 'secret12345', 'role' => 'member']);
        $r = $this->createRecord(['f_name' => 'ปลายทาง', 'f_email' => 'enduser@example.com']);
        AppRecord::query()->whereKey($r['id'])->update(['app_user_id' => $u->id]);
        $export = $this->actingAs($this->owner)->get("/api/v1/projects/{$this->project->id}/app-users/{$u->id}/export")->assertOk()->json();
        $this->assertSame('enduser@example.com', $export['account']['email']);
        $this->assertCount(1, $export['records']);
        $this->assertArrayNotHasKey('password', $export['account']);
        $this->actingAs($this->owner)->deleteJson("/api/v1/projects/{$this->project->id}/app-users/{$u->id}?deleteRecords=1")->assertOk();
        $this->assertNull(AppUser::query()->find($u->id));
        $this->assertSame(0, AppRecord::query()->count());
    }

    public function test_retention_removes_old_operational_data(): void
    {
        DB::table('webhook_endpoints')->insert(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'project_id' => $this->project->id, 'environment' => 'preview', 'url' => 'https://x.example', 'events' => '[]', 'secret' => encrypt('s'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('webhook_deliveries')->insert(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAW', 'endpoint_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'event_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAX', 'event' => 'record.created', 'payload' => '{}', 'status' => 'succeeded', 'created_at' => now()->subDays(40), 'updated_at' => now()]);
        $this->artisan('studio:retention:run')->assertSuccessful();
        $this->assertSame(0, DB::table('webhook_deliveries')->count());
    }

    public function test_audit_chain_detects_tampering_deletion_and_truncation(): void
    {
        $logger = app(AuditLogger::class);
        foreach (range(1, 5) as $i) {
            $logger->record('test.event', null, ['n' => $i, 'nested' => ['b' => 2, 'a' => 1]]);
        }
        $this->assertNull(AuditLogger::verify());
        $this->artisan('studio:audit:verify')->assertSuccessful();

        $target = AuditLog::query()->where('action', 'test.event')->orderBy('id')->skip(2)->first();
        DB::table('audit_logs')->where('id', $target->id)->update(['metadata' => json_encode(['n' => 999, 'nested' => ['a' => 1, 'b' => 2]])]);
        $this->assertSame($target->id, AuditLogger::verify()['id']);
        $this->artisan('studio:audit:verify')->assertFailed();

        DB::table('audit_logs')->where('id', $target->id)->update(['metadata' => json_encode(['n' => 3, 'nested' => ['b' => 2, 'a' => 1]])]);
        $this->assertNull(AuditLogger::verify());
        DB::table('audit_logs')->where('id', $target->id)->delete();
        $this->assertSame('link mismatch (entry removed or reordered)', AuditLogger::verify()['reason']);
    }

    public function test_removing_the_latest_entries_is_detected(): void
    {
        app(AuditLogger::class)->record('test.a');
        app(AuditLogger::class)->record('test.b');
        DB::table('audit_logs')->orderByDesc('id')->limit(1)->delete();
        $this->assertSame(0, AuditLogger::verify()['id']);
    }
}
