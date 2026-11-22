<?php

namespace Tests\Feature;

use App\Domain\Publishing\DnsResolver;
use App\Domain\Publishing\TlsProbe;
use App\Models\Domain;

class FakeDns implements DnsResolver
{
    public array $txt = [];

    public array $cname = [];

    public array $a = [];

    public function txt(string $name): array
    {
        return $this->txt[$name] ?? [];
    }

    public function cname(string $name): array
    {
        return $this->cname[$name] ?? [];
    }

    public function a(string $name): array
    {
        return $this->a[$name] ?? [];
    }
}

class FakeTls implements TlsProbe
{
    public bool $ok = false;

    public function valid(string $host): bool
    {
        return $this->ok;
    }
}

/** J6: custom domain ownership + routing + certificate, takeover protection and edge TLS authorisation. */
class CustomDomainTest extends DataTestCase
{
    private FakeDns $dns;

    private FakeTls $tls;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dns = new FakeDns;
        $this->tls = new FakeTls;
        $this->app->instance(DnsResolver::class, $this->dns);
        $this->app->instance(TlsProbe::class, $this->tls);
        config(['studio.publishing.require_verified_email' => false]);
        $this->actingAs($this->owner)->putJson("/api/v1/projects/{$this->project->id}/subdomain", ['slug' => 'shop'])->assertOk();
        $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/publish")->assertStatus(202);
    }

    private function add(string $host)
    {
        return $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/domains", ['hostname' => $host]);
    }

    public function test_j6_custom_domain_goes_live_only_after_ownership_routing_and_tls(): void
    {
        $res = $this->add('https://WWW.Example.co.th/path')->assertCreated()
            ->assertJsonPath('data.hostname', 'www.example.co.th')->assertJsonPath('data.status', 'pending_verification')
            ->assertJsonPath('data.instructions.records.0.type', 'TXT')
            ->assertJsonPath('data.instructions.records.0.name', '_studio-verify.www.example.co.th')
            ->assertJsonPath('data.instructions.records.1.type', 'CNAME');
        $id = $res->json('data.id');
        $token = $res->json('data.instructions.records.0.value');
        $verify = fn () => $this->actingAs($this->owner)->postJson("/api/v1/domains/{$id}/verify")->assertOk();

        $verify()->assertJsonPath('data.status', 'pending_verification')->assertJsonPath('data.checks.ownership.ok', false);
        $this->get('https://www.example.co.th/')->assertNotFound();
        $this->getJson('/api/internal/tls/ask?domain=www.example.co.th')->assertNotFound();

        // Routing alone (someone else's CNAME) is not enough: ownership TXT is required.
        $this->dns->cname['www.example.co.th'] = ['edge.sites.localhost'];
        $verify()->assertJsonPath('data.status', 'pending_verification')->assertJsonPath('data.checks.routing.ok', true);

        $this->dns->txt['_studio-verify.www.example.co.th'] = [$token];
        $verify()->assertJsonPath('data.status', 'verified')->assertJsonPath('data.checks.certificate.ok', false);
        $this->getJson('/api/internal/tls/ask?domain=www.example.co.th')->assertOk(); // edge may now obtain a certificate
        $this->get('https://www.example.co.th/')->assertNotFound(); // not served before HTTPS works

        $this->tls->ok = true;
        $verify()->assertJsonPath('data.status', 'active');
        $this->get('https://www.example.co.th/')->assertOk()->assertSee('__SITE__', false);

        // DNS moved away → the scheduled re-check deactivates it.
        $this->dns->cname['www.example.co.th'] = ['someone-else.example.net'];
        $this->artisan('studio:domains:check')->assertSuccessful();
        $this->assertSame('pending_verification', Domain::query()->find($id)->status);
        $this->get('https://www.example.co.th/')->assertNotFound();
        $this->getJson('/api/internal/tls/ask?domain=www.example.co.th')->assertNotFound();
    }

    public function test_hostname_bound_to_one_project_and_must_be_revalidated_after_removal(): void
    {
        $id = $this->add('shop.example.com')->assertCreated()->json('data.id');
        $token = Domain::query()->find($id)->verification_token;

        [$other, $otherWs] = $this->tenant('Other');
        $otherProject = $this->project($other, $otherWs);
        $this->actingAs($other)->postJson("/api/v1/projects/{$otherProject->id}/domains", ['hostname' => 'shop.example.com'])
            ->assertStatus(409)->assertJsonPath('error.code', 'hostname_taken');
        $this->actingAs($other)->postJson("/api/v1/domains/{$id}/verify")->assertNotFound();
        $this->actingAs($other)->deleteJson("/api/v1/domains/{$id}")->assertNotFound();

        $this->actingAs($this->owner)->deleteJson("/api/v1/domains/{$id}")->assertOk();
        $newId = $this->actingAs($other)->postJson("/api/v1/projects/{$otherProject->id}/domains", ['hostname' => 'shop.example.com'])->assertCreated()->json('data.id');
        // A new token is issued: the previous owner's TXT record does not verify the new binding.
        $this->assertNotSame($token, Domain::query()->find($newId)->verification_token);
        $this->dns->txt['_studio-verify.shop.example.com'] = [$token];
        $this->dns->cname['shop.example.com'] = ['edge.sites.localhost'];
        $this->actingAs($other)->postJson("/api/v1/domains/{$newId}/verify")->assertOk()->assertJsonPath('data.checks.ownership.ok', false);
    }

    public function test_rejects_platform_hosts_invalid_names_and_subdomain_removal(): void
    {
        $this->add('evil.sites.localhost')->assertStatus(422)->assertJsonPath('error.code', 'invalid_hostname');
        $this->add('localhost')->assertStatus(422);
        $this->add('not a domain')->assertStatus(422);
        $this->add('127.0.0.1')->assertStatus(422);
        $this->add('example.com')->assertCreated()->assertJsonPath('data.instructions.apex', true);
        $sub = Domain::query()->where('type', 'subdomain')->value('id');
        $this->actingAs($this->owner)->deleteJson("/api/v1/domains/{$sub}")->assertStatus(422);

        $viewer = $this->addMember($this->ws, 'viewer');
        $this->actingAs($viewer)->postJson("/api/v1/projects/{$this->project->id}/domains", ['hostname' => 'b.example.com'])->assertForbidden();
    }

    public function test_tls_ask_allows_only_served_hostnames(): void
    {
        $this->getJson('/api/internal/tls/ask?domain=shop.sites.localhost')->assertOk();
        $this->getJson('/api/internal/tls/ask?domain=other.sites.localhost')->assertNotFound();
        $this->getJson('/api/internal/tls/ask?domain=random.example.org')->assertNotFound();
        $this->getJson('/api/internal/tls/ask')->assertNotFound();
    }
}
