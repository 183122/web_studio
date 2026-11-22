<?php

namespace App\Jobs;

use App\Domain\Integrations\Webhooks\EgressGuard;
use App\Domain\Integrations\Webhooks\WebhookService;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Signed POST with SSRF guard, DNS pinning, no redirects, short timeouts. Retried with backoff;
 * exhausted deliveries become `dead` and can be redelivered. Endpoints are auto-disabled after
 * repeated dead deliveries or a 410 Gone.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public const DISABLE_AFTER = 15;

    public int $tries = 6;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 1800, 7200];

    public function __construct(public string $deliveryId)
    {
        $this->onQueue('webhooks');
    }

    public function handle(EgressGuard $guard): void
    {
        $delivery = WebhookDelivery::query()->find($this->deliveryId);
        $endpoint = $delivery ? WebhookEndpoint::query()->find($delivery->endpoint_id) : null;
        if ($delivery === null || $endpoint === null || ! $endpoint->enabled || $delivery->status === 'succeeded') {
            return;
        }
        $delivery->forceFill(['attempts' => $delivery->attempts + 1])->save();
        try {
            $target = $guard->check($endpoint->url);
        } catch (Throwable $e) {
            $delivery->forceFill(['status' => 'dead', 'error' => mb_substr($e->getMessage(), 0, 400)])->save();

            return; // blocked destinations are never retried
        }
        $body = json_encode($delivery->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ts = time();
        try {
            $res = Http::withHeaders([
                'Content-Type' => 'application/json',
                'User-Agent' => 'AIStudioCloud-Webhooks/1.0',
                'X-Studio-Event' => $delivery->event,
                'X-Studio-Event-Id' => $delivery->event_id,
                'X-Studio-Delivery' => $delivery->id,
                'X-Studio-Signature' => WebhookService::sign($endpoint->secret, $body, $ts),
            ])->withOptions([
                'allow_redirects' => false,
                'curl' => [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:".(str_contains($target['ip'], ':') ? "[{$target['ip']}]" : $target['ip'])]],
            ])->connectTimeout(5)->timeout(10)->withBody($body, 'application/json')->post($endpoint->url);
        } catch (Throwable $e) {
            $this->fail_($delivery, null, 'เชื่อมต่อไม่ได้: '.mb_substr($e->getMessage(), 0, 200));
        }
        if ($res->status() === 410) {
            $endpoint->forceFill(['enabled' => false, 'disabled_reason' => 'ปลายทางตอบ 410 Gone'])->save();
            $delivery->forceFill(['status' => 'dead', 'response_status' => 410, 'error' => 'endpoint gone'])->save();

            return;
        }
        if (! $res->successful()) {
            $this->fail_($delivery, $res->status(), 'ปลายทางตอบ HTTP '.$res->status());
        }
        $delivery->forceFill(['status' => 'succeeded', 'response_status' => $res->status(), 'error' => null, 'delivered_at' => now()])->save();
        $endpoint->forceFill(['consecutive_failures' => 0])->save();
    }

    private function fail_(WebhookDelivery $d, ?int $status, string $error): never
    {
        $d->forceFill(['status' => 'failed', 'response_status' => $status, 'error' => $error])->save();
        throw new RuntimeException($error);
    }

    public function failed(Throwable $e): void
    {
        $delivery = WebhookDelivery::query()->find($this->deliveryId);
        if ($delivery === null || $delivery->status === 'succeeded') {
            return;
        }
        $delivery->forceFill(['status' => 'dead'])->save();
        $endpoint = WebhookEndpoint::query()->find($delivery->endpoint_id);
        if ($endpoint) {
            $endpoint->increment('consecutive_failures');
            if ($endpoint->consecutive_failures >= self::DISABLE_AFTER) {
                $endpoint->forceFill(['enabled' => false, 'disabled_reason' => 'ส่งไม่สำเร็จติดต่อกัน '.self::DISABLE_AFTER.' ครั้ง'])->save();
            }
        }
    }
}
