<?php

namespace App\Domain\Billing;

use App\Models\Invoice;
use App\Support\ApiException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Verified, idempotent webhook handling. Each provider event id is processed at most once
 * (unique row), amounts/currency must match the invoice, and unknown references are recorded
 * but ignored. Redirects back from checkout never change billing state — only this does.
 */
class BillingWebhookProcessor
{
    public function __construct(private readonly BillingService $billing) {}

    /** @return string processed | duplicate | ignored */
    public function receive(string $provider, string $rawBody, array $headers): string
    {
        $adapter = $this->billing->provider();
        if ($adapter->name() !== $provider) {
            throw new ApiException(404, 'not_found', 'ไม่รู้จักผู้ให้บริการนี้');
        }
        $headers = array_change_key_case($headers);
        $event = $adapter->parseWebhook($rawBody, $headers); // throws on bad signature / stale timestamp

        try {
            $row = DB::table('billing_webhook_events')->insertGetId([
                'provider' => $provider, 'event_id' => $event['id'], 'type' => $event['type'], 'payload' => json_encode($event['data']),
                'status' => 'received', 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return 'duplicate';
        }

        [$status, $note] = DB::transaction(fn () => $this->apply($provider, $event));
        DB::table('billing_webhook_events')->where('id', $row)->update(['status' => $status, 'note' => $note, 'processed_at' => now(), 'updated_at' => now()]);

        return $status;
    }

    /** @return array{0: string, 1: ?string} */
    private function apply(string $provider, array $event): array
    {
        $d = $event['data'];
        $ref = match ($event['type']) {
            'checkout.completed', 'checkout.failed' => $d['checkoutRef'] ?? null,
            'charge.succeeded', 'charge.failed' => $d['invoiceRef'] ?? null,
            default => null,
        };
        if ($ref === null) {
            return ['ignored', 'unsupported event type'];
        }
        $invoice = Invoice::query()->where('provider', $provider)->where('provider_ref', $ref)->lockForUpdate()->first();
        if ($invoice === null) {
            return ['ignored', 'unknown invoice reference'];
        }
        if ((int) ($d['amount'] ?? -1) !== $invoice->total_minor || ($d['currency'] ?? '') !== $invoice->currency) {
            return ['rejected', 'amount/currency mismatch'];
        }
        $paymentRef = (string) ($d['paymentRef'] ?? $d['chargeRef'] ?? '');
        if (str_ends_with($event['type'], 'completed') || str_ends_with($event['type'], 'succeeded')) {
            $this->billing->markPaid($invoice, $paymentRef, $d['paymentMethodRef'] ?? null);
        } else {
            $this->billing->markFailed($invoice, $paymentRef, (string) ($d['reason'] ?? 'declined'));
        }

        return ['processed', null];
    }
}
