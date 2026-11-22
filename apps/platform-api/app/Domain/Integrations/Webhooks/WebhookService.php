<?php

namespace App\Domain\Integrations\Webhooks;

use App\Jobs\DeliverWebhook;
use App\Models\AppRecord;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Str;

/** Fan-out of app data events to subscribed endpoints (M25). Deliveries are queued after commit. */
class WebhookService
{
    public const EVENTS = ['record.created', 'record.updated', 'record.deleted'];

    /** Signature header value: t=<unix>,v1=<hex hmac_sha256(secret, "<t>.<body>")>. */
    public static function sign(string $secret, string $body, int $timestamp): string
    {
        return "t={$timestamp},v1=".hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    public function recordChanged(string $projectId, array $model, AppRecord $record, string $event): void
    {
        $endpoints = WebhookEndpoint::query()->where('project_id', $projectId)->where('environment', $record->environment)->where('enabled', true)->get()
            ->filter(fn (WebhookEndpoint $e) => in_array($event, $e->events, true));
        if ($endpoints->isEmpty()) {
            return;
        }
        $eventId = (string) Str::ulid();
        $values = $event === 'record.deleted' ? null : $record->values();
        $fields = [];
        foreach ($model['fields'] as $f) {
            $fields[$f['name']] = $values[$f['id']] ?? null;
        }
        $payload = [
            'id' => $eventId,
            'type' => $event,
            'createdAt' => now()->toIso8601String(),
            'environment' => $record->environment,
            'model' => ['id' => $model['id'], 'name' => $model['name']],
            'record' => ['id' => $record->id, 'version' => $record->version] + ($values === null ? [] : ['fields' => (object) $fields]),
        ];
        foreach ($endpoints as $endpoint) {
            $delivery = WebhookDelivery::create(['endpoint_id' => $endpoint->id, 'event_id' => $eventId, 'event' => $event, 'payload' => $payload, 'status' => 'pending']);
            DeliverWebhook::dispatch($delivery->id)->afterCommit();
        }
    }
}
