<?php

namespace Tests\Feature;

use App\Jobs\SendBillingEmail;
use App\Models\Domain;
use App\Services\UsageMeter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/** Metering: atomic counters, warnings, soft/hard limits that never delete data. */
class MeteringTest extends DataTestCase
{
    private function publish(string $slug = 'meter'): void
    {
        config(['studio.publishing.require_verified_email' => false]);
        $this->actingAs($this->owner)->putJson("/api/v1/projects/{$this->project->id}/subdomain", ['slug' => $slug])->assertOk();
        $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/publish")->assertStatus(202);
    }

    public function test_counters_are_atomic_per_org_metric_and_month(): void
    {
        $meter = app(UsageMeter::class);
        $org = $this->ws->organization_id;
        for ($i = 0; $i < 5; $i++) {
            $meter->add($org, 'runtime_requests', 3);
        }
        $this->assertSame(15, $meter->value($org, 'runtime_requests'));
        $this->assertSame(1, DB::table('usage_counters')->where('organization_id', $org)->where('metric', 'runtime_requests')->count());
        $this->travel(1)->months();
        $this->assertSame(0, $meter->value($org, 'runtime_requests'));
    }

    public function test_site_requests_and_publishing_are_metered_with_warnings_and_hard_limit(): void
    {
        Queue::fake([SendBillingEmail::class]);
        $this->publish();
        $org = $this->ws->organization;
        $meter = app(UsageMeter::class);
        $this->assertGreaterThanOrEqual(1, $meter->value($org->id, 'build_seconds'));

        $this->get('http://meter.sites.localhost/')->assertOk();
        $this->assertSame(1, $meter->value($org->id, 'runtime_requests'));

        // Free plan: 10,000/month. 80% and 100% warnings are sent once each.
        $meter->add($org->id, 'runtime_requests', 7999);
        Queue::assertPushed(SendBillingEmail::class, 1);
        $meter->add($org->id, 'runtime_requests', 2000);
        $meter->add($org->id, 'runtime_requests', 10);
        Queue::assertPushed(SendBillingEmail::class, 2);
        $this->get('http://meter.sites.localhost/')->assertOk(); // soft limit: still served

        $meter->add($org->id, 'runtime_requests', 5000); // > 150%
        Cache::flush();
        $this->get('http://meter.sites.localhost/')->assertStatus(503)->assertSee('เกินโควตา', false);
        $this->withHeaders(['X-Runtime-Request' => '1'])->getJson('http://meter.sites.localhost/api/runtime/v1/auth/me')->assertStatus(503)->assertJsonPath('error.code', 'site_quota_exceeded');

        // Upgrading lifts the block immediately (cache cleared on plan change is not needed: limit re-read after 60s; flush here).
        $this->onPlan('pro');
        Cache::flush();
        $this->get('http://meter.sites.localhost/')->assertOk();
        $this->actingAs($this->owner)->getJson("/api/v1/organizations/{$org->id}/billing")->assertOk()
            ->assertJsonPath('data.usage.runtime_requests.limit', 500000);
    }

    public function test_build_minutes_limit_blocks_new_publishes(): void
    {
        app(UsageMeter::class)->add($this->ws->organization_id, 'build_seconds', 30 * 60);
        config(['studio.publishing.require_verified_email' => false]);
        $this->actingAs($this->owner)->putJson("/api/v1/projects/{$this->project->id}/subdomain", ['slug' => 'meter2'])->assertOk();
        $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/publish")->assertStatus(422)->assertJsonPath('error.code', 'quota_exceeded');
    }

    public function test_custom_domains_follow_the_plan(): void
    {
        $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/domains", ['hostname' => 'www.example.com'])->assertStatus(422)->assertJsonPath('error.code', 'quota_exceeded');
        $this->onPlan('pro');
        $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/domains", ['hostname' => 'www.example.com'])->assertCreated();
        // Downgrade keeps the domain but blocks adding more.
        $this->onPlan('free');
        $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/domains", ['hostname' => 'shop.example.com'])->assertStatus(422);
        $this->assertSame(1, Domain::query()->where('type', 'custom')->whereNull('released_at')->count());
    }
}
