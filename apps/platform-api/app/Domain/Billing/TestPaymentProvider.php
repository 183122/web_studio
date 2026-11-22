<?php

namespace App\Domain\Billing;

use App\Models\Invoice;
use App\Models\Subscription;
use App\Support\ApiException;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sandbox provider: hosted checkout is a studio page that simulates the customer's choice; the
 * simulator then emits signed webhooks exactly like a real provider would. No money moves.
 * Refused in production so it can never be used to obtain paid plans.
 */
class TestPaymentProvider implements PaymentProvider
{
    public const METHOD_OK = 'tok_test_ok';

    /** Succeeds on checkout, declines every later renewal (to exercise dunning). */
    public const METHOD_DECLINE_RENEWALS = 'tok_test_decline_renewals';

    public function __construct()
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The test payment provider cannot run in production; billing is not activated.');
        }
    }

    public function name(): string
    {
        return 'test';
    }

    public function createCheckout(Invoice $invoice, string $returnUrl): array
    {
        $ref = 'cs_test_'.Str::random(24);

        return ['ref' => $ref, 'url' => rtrim((string) config('studio.frontend_url'), '/').'/billing/test-checkout/'.$ref.'?return='.rawurlencode($returnUrl)];
    }

    public function chargeRenewal(Subscription $subscription, Invoice $invoice): string
    {
        $ref = 'ch_test_'.Str::random(24);
        $ok = $subscription->payment_method_ref === self::METHOD_OK;
        $this->emit($ok ? 'charge.succeeded' : 'charge.failed', [
            'chargeRef' => $ref, 'invoiceRef' => $invoice->provider_ref, 'amount' => $invoice->total_minor, 'currency' => $invoice->currency,
            'reason' => $ok ? null : 'card_declined',
        ]);

        return $ref;
    }

    /** Customer finished (or abandoned) the hosted checkout. */
    public function completeCheckout(Invoice $invoice, string $outcome): void
    {
        $paid = in_array($outcome, ['success', 'success_decline_renewals'], true);
        $this->emit($paid ? 'checkout.completed' : 'checkout.failed', [
            'checkoutRef' => $invoice->provider_ref, 'paymentRef' => 'pay_test_'.Str::random(20), 'amount' => $invoice->total_minor,
            'currency' => $invoice->currency, 'paymentMethodRef' => $outcome === 'success' ? self::METHOD_OK : self::METHOD_DECLINE_RENEWALS,
            'reason' => $paid ? null : 'card_declined',
        ]);
    }

    /** Signs and delivers through the same verification path as an external HTTP webhook. */
    private function emit(string $type, array $data): void
    {
        $body = json_encode(['id' => 'evt_test_'.Str::random(24), 'type' => $type, 'created' => time(), 'data' => $data], JSON_UNESCAPED_SLASHES);
        app(BillingWebhookProcessor::class)->receive('test', $body, [
            'x-billing-signature' => WebhookSignature::sign((string) config('studio.billing.webhook_secret'), $body, time()),
        ]);
    }

    public function parseWebhook(string $rawBody, array $headers): array
    {
        WebhookSignature::verify((string) config('studio.billing.webhook_secret'), $rawBody, $headers['x-billing-signature'] ?? null, (int) config('studio.billing.webhook_tolerance_seconds'));
        $event = json_decode($rawBody, true);
        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null) || ! is_array($event['data'] ?? null)) {
            throw new ApiException(400, 'invalid_payload', 'รูปแบบเหตุการณ์ไม่ถูกต้อง');
        }

        return ['id' => $event['id'], 'type' => $event['type'], 'data' => $event['data']];
    }
}
