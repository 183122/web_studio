<?php

namespace App\Domain\Billing;

use App\Models\Invoice;
use App\Models\Subscription;

/**
 * Payment provider adapter (Thai-compatible providers implement this). Payment state changes only
 * arrive through verified webhooks (`BillingWebhookProcessor`); return values here never mark
 * anything as paid.
 */
interface PaymentProvider
{
    public function name(): string;

    /** Hosted checkout for an open invoice. @return array{ref: string, url: string} */
    public function createCheckout(Invoice $invoice, string $returnUrl): array;

    /** Off-session charge of the saved payment method for a renewal. Result arrives by webhook. */
    public function chargeRenewal(Subscription $subscription, Invoice $invoice): string;

    /** Verifies signature + timestamp of a raw webhook. @return array{id: string, type: string, data: array} */
    public function parseWebhook(string $rawBody, array $headers): array;
}
