<?php

namespace Tests\Feature;

use App\Domain\Integrations\Webhooks\EgressGuard;
use App\Domain\Integrations\Webhooks\WebhookService;
use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\ApiException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** M25: SSRF-guarded, signed, retried outbound webhooks. */
class WebhookTest extends DataTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Deterministic DNS for tests: every hostname resolves to a public documentation-free address.
        $this->app->instance(EgressGuard::class, new EgressGuard(fn (string $host) => match ($host) {
            'internal.example' => ['10.0.0.5'],
            'rebind.example' => ['93.184.216.34', '127.0.0.1'],
            default => ['93.184.216.34'],
        }));
    }

    private function api(string $path): string
    {
        return "/api/v1/projects/{$this->project->id}{$path}";
    }

    private function endpoint(array $over = []): array
    {
        return $this->actingAs($this->owner)->postJson($this->api('/webhooks'), array_merge([
            'url' => 'https://hooks.example.com/studio', 'environment' => 'preview', 'events' => ['record.created', 'record.deleted'],
        ], $over))->assertCreated()->json('data');
    }

    public function test_ssrf_guard_blocks_internal_and_unsafe_destinations(): void
    {
        $guard = app(EgressGuard::class);
        foreach ([
            'http://hooks.example.com/x', 'https://127.0.0.1/x', 'https://169.254.169.254/latest/meta-data', 'https://10.1.2.3/',
            'https://[::1]/', 'https://user:pass@hooks.example.com/', 'https://hooks.example.com:22/', 'https://internal.example/',
            'https://rebind.example/', 'ftp://hooks.example.com/', 'https://100.64.0.1/', 'https://[fd00::1]/',
        ] as $url) {
            try {
                $guard->check($url);
                $this->fail("allowed {$url}");
            } catch (ApiException $e) {
                $this->assertSame('url_not_allowed', $e->errorCode, $url);
            }
        }
        $this->assertSame('93.184.216.34', $guard->check('https://hooks.example.com/x')['ip']);
        $this->actingAs($this->owner)->postJson($this->api('/webhooks'), ['url' => 'https://169.254.169.254/', 'environment' => 'preview', 'events' => ['record.created']])
            ->assertStatus(422)->assertJsonPath('error.code', 'url_not_allowed');
    }

    public function test_signed_delivery_for_record_events_and_secret_shown_once(): void
    {
        $received = [];
        Http::fake(['hooks.example.com/*' => function (Request $r) use (&$received) {
            $received[] = $r;

            return Http::response(['ok' => true]);
        }]);
        $ep = $this->endpoint();
        $this->assertStringStartsWith('whsec_', $ep['secret']);
        $list = $this->actingAs($this->owner)->getJson($this->api('/webhooks'))->assertOk();
        $this->assertStringNotContainsString($ep['secret'], $list->getContent());

        $r = $this->createRecord(['f_name' => 'สมชาย', 'f_email' => 's@example.com']);
        $this->assertCount(1, $received);
        $req = $received[0];
        $body = $req->body();
        $payload = json_decode($body, true);
        $this->assertSame('record.created', $payload['type']);
        $this->assertSame('สมชาย', $payload['record']['fields']['full_name']);
        $this->assertSame($payload['id'], $req->header('X-Studio-Event-Id')[0]);
        preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $req->header('X-Studio-Signature')[0], $m);
        $this->assertSame(WebhookService::sign($ep['secret'], $body, (int) $m[1]), $req->header('X-Studio-Signature')[0]);

        // Not subscribed to updates; deletions are delivered without field values.
        $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$this->project->id}/data/m_reg/records/{$r['id']}", ['data' => ['f_seats' => 2], 'version' => 1])->assertOk();
        $this->assertCount(1, $received);
        $this->actingAs($this->owner)->deleteJson("/api/v1/projects/{$this->project->id}/data/m_reg/records/{$r['id']}")->assertOk();
        $this->assertCount(2, $received);
        $this->assertArrayNotHasKey('fields', json_decode($received[1]->body(), true)['record']);

        $this->actingAs($this->owner)->getJson($this->api("/webhooks/{$ep['id']}/deliveries"))->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('data.0.status', 'succeeded');
    }

    public function test_failures_retry_then_dead_letter_and_redelivery(): void
    {
        $status = 500;
        Http::fake(['hooks.example.com/*' => function () use (&$status) {
            return Http::response('nope', $status);
        }]);
        $ep = $this->endpoint();
        try {
            $this->createRecord(['f_name' => 'ก', 'f_email' => 'k@example.com']);
        } catch (\Throwable) {
            // sync queue rethrows the delivery failure in tests; real workers retry with backoff
        }
        $d = WebhookDelivery::query()->sole();
        $this->assertSame('dead', $d->status); // sync driver: one attempt then failed() → dead letter
        $this->assertSame(500, $d->response_status);
        $this->assertSame([10, 60, 300, 1800, 7200], (new DeliverWebhook($d->id))->backoff);

        $status = 200;
        $this->actingAs($this->owner)->postJson($this->api("/webhook-deliveries/{$d->id}/redeliver"))->assertStatus(202);
        $this->assertSame('succeeded', WebhookDelivery::query()->where('event_id', $d->event_id)->latest('created_at')->get()->firstWhere('status', 'succeeded')?->status);
    }

    public function test_gone_endpoint_is_disabled_and_preview_production_are_separate(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response('', 410)]);
        $ep = $this->endpoint();
        $prod = $this->endpoint(['environment' => 'production']);
        $this->createRecord(['f_name' => 'ก', 'f_email' => 'k@example.com']);
        $this->assertFalse(WebhookEndpoint::query()->find($ep['id'])->enabled);
        $this->assertSame(0, WebhookDelivery::query()->where('endpoint_id', $prod['id'])->count());
        $this->actingAs($this->owner)->patchJson($this->api("/webhooks/{$ep['id']}"), ['enabled' => true])->assertOk()->assertJsonPath('data.enabled', true);
    }

    public function test_viewers_and_outsiders_cannot_manage_webhooks(): void
    {
        $viewer = $this->addMember($this->ws, 'viewer');
        $this->actingAs($viewer)->postJson($this->api('/webhooks'), ['url' => 'https://hooks.example.com/x', 'environment' => 'preview', 'events' => ['record.created']])->assertForbidden();
        [$stranger] = $this->tenant('Stranger');
        $this->actingAs($stranger)->getJson($this->api('/webhooks'))->assertNotFound();
    }
}
