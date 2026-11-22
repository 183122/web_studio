<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Domain\Integrations\Webhooks\EgressGuard;
use App\Domain\Integrations\Webhooks\WebhookService;
use App\Http\Controllers\Controller;
use App\Jobs\DeliverWebhook;
use App\Models\Project;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\AuditLogger;
use App\Support\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** M25: outbound webhook endpoints, delivery log and redelivery. */
class WebhookController extends Controller
{
    public function __construct(private readonly EgressGuard $guard, private readonly AuditLogger $audit) {}

    private static function present(WebhookEndpoint $e): array
    {
        return [
            'id' => $e->id, 'url' => $e->url, 'environment' => $e->environment, 'events' => $e->events, 'description' => $e->description,
            'enabled' => $e->enabled, 'disabledReason' => $e->disabled_reason, 'consecutiveFailures' => $e->consecutive_failures,
            'createdAt' => $e->created_at?->toIso8601String(),
        ];
    }

    private static function presentDelivery(WebhookDelivery $d): array
    {
        return [
            'id' => $d->id, 'eventId' => $d->event_id, 'event' => $d->event, 'status' => $d->status, 'attempts' => $d->attempts,
            'responseStatus' => $d->response_status, 'error' => $d->error, 'createdAt' => $d->created_at?->toIso8601String(),
            'deliveredAt' => $d->delivered_at?->toIso8601String(),
        ];
    }

    private function find(Project $p, string $id): WebhookEndpoint
    {
        return WebhookEndpoint::query()->where('project_id', $p->id)->whereKey($id)->firstOr(fn () => throw new ApiException(404, 'not_found', 'ไม่พบ webhook'));
    }

    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json(['data' => WebhookEndpoint::query()->where('project_id', $project->id)->orderBy('created_at')->get()->map(fn ($e) => self::present($e))]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('manage', $project);
        $d = $request->validate([
            'url' => ['required', 'string', 'max:500', 'url'],
            'environment' => ['required', Rule::in(['preview', 'production'])],
            'events' => ['required', 'array', 'min:1'], 'events.*' => [Rule::in(WebhookService::EVENTS)],
            'description' => ['nullable', 'string', 'max:200'],
        ]);
        $this->guard->check($d['url']);
        if (WebhookEndpoint::query()->where('project_id', $project->id)->count() >= 10) {
            throw ApiException::unprocessable('too_many_webhooks', 'สร้าง webhook ได้สูงสุด 10 รายการต่อโปรเจกต์');
        }
        $secret = 'whsec_'.Str::random(40);
        $e = WebhookEndpoint::create([
            'project_id' => $project->id, 'environment' => $d['environment'], 'url' => $d['url'], 'events' => array_values(array_unique($d['events'])),
            'secret' => $secret, 'description' => $d['description'] ?? '', 'created_by' => $request->user()->id,
        ]);
        $this->audit->record('webhook.created', $project, ['endpoint_id' => $e->id, 'host' => parse_url($d['url'], PHP_URL_HOST)]);

        // The signing secret is shown exactly once.
        return response()->json(['data' => self::present($e) + ['secret' => $secret]], 201);
    }

    public function update(Request $request, Project $project, string $id): JsonResponse
    {
        $this->authorize('manage', $project);
        $e = $this->find($project, $id);
        $d = $request->validate(['enabled' => ['sometimes', 'boolean'], 'events' => ['sometimes', 'array', 'min:1'], 'events.*' => [Rule::in(WebhookService::EVENTS)]]);
        if (($d['enabled'] ?? false) === true) {
            $this->guard->check($e->url);
            $e->consecutive_failures = 0;
            $e->disabled_reason = null;
        }
        $e->fill($d)->save();

        return response()->json(['data' => self::present($e)]);
    }

    public function destroy(Project $project, string $id): JsonResponse
    {
        $this->authorize('manage', $project);
        $this->find($project, $id)->delete();
        $this->audit->record('webhook.deleted', $project, ['endpoint_id' => $id]);

        return response()->json(['data' => ['ok' => true]]);
    }

    public function deliveries(Project $project, string $id): JsonResponse
    {
        $this->authorize('view', $project);
        $e = $this->find($project, $id);

        return response()->json(['data' => WebhookDelivery::query()->where('endpoint_id', $e->id)->latest('created_at')->latest('id')->limit(50)->get()->map(fn ($d) => self::presentDelivery($d))]);
    }

    public function redeliver(Project $project, string $deliveryId): JsonResponse
    {
        $this->authorize('manage', $project);
        $d = WebhookDelivery::query()->whereKey($deliveryId)
            ->whereIn('endpoint_id', WebhookEndpoint::query()->where('project_id', $project->id)->select('id'))
            ->firstOr(fn () => throw new ApiException(404, 'not_found', 'ไม่พบการส่ง'));
        $e = WebhookEndpoint::query()->findOrFail($d->endpoint_id);
        if (! $e->enabled) {
            throw ApiException::conflict('endpoint_disabled', 'webhook นี้ถูกปิดใช้งาน กรุณาเปิดใช้งานก่อน');
        }
        $copy = WebhookDelivery::create(['endpoint_id' => $e->id, 'event_id' => $d->event_id, 'event' => $d->event, 'payload' => $d->payload, 'status' => 'pending']);
        DeliverWebhook::dispatch($copy->id);

        return response()->json(['data' => self::presentDelivery($copy->fresh())], 202);
    }
}
