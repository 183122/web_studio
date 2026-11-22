<?php

namespace App\Domain\Billing;

use App\Jobs\SendBillingEmail;
use App\Models\BillingProfile;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ApiException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Subscription lifecycle (M03): checkout → (webhook) paid → active; monthly renewal charges;
 * dunning with retries; cancellation at period end; trials; downgrade to the free plan that keeps
 * all data (quota checks then block only *new* usage above the free limits).
 */
class BillingService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function provider(): PaymentProvider
    {
        return match (config('studio.billing.provider')) {
            'test' => app()->isProduction()
                ? throw new ApiException(503, 'billing_not_activated', 'ระบบชำระเงินยังไม่เปิดใช้งาน')
                : new TestPaymentProvider,
            default => throw new ApiException(503, 'billing_not_activated', 'ระบบชำระเงินยังไม่เปิดใช้งาน'),
        };
    }

    public function freePlan(): Plan
    {
        return Plan::query()->where('code', config('studio.billing.free_plan'))->firstOrFail();
    }

    public static function amounts(Plan $plan): array
    {
        $vatBp = (int) config('studio.billing.vat_rate_bp');
        $subtotal = $plan->price_minor;
        $vat = intdiv($subtotal * $vatBp + 5000, 10000); // round half up to the satang

        return ['subtotal_minor' => $subtotal, 'vat_rate_bp' => $vatBp, 'vat_minor' => $vat, 'total_minor' => $subtotal + $vat];
    }

    private function buyer(Organization $org): ?array
    {
        $p = BillingProfile::query()->where('organization_id', $org->id)->first();

        return $p ? ['legalName' => $p->legal_name, 'taxId' => $p->tax_id, 'branch' => $p->branch, 'address' => $p->address, 'email' => $p->email] : null;
    }

    private function newInvoice(Organization $org, Plan $plan, string $kind, Carbon $start, ?Subscription $sub, ?User $by): Invoice
    {
        return Invoice::create([
            'organization_id' => $org->id, 'subscription_id' => $sub?->id, 'plan_id' => $plan->id, 'kind' => $kind, 'status' => 'open',
            'currency' => $plan->currency, 'lines' => [['description' => "แพ็กเกจ {$plan->name} (รายเดือน) {$start->format('d/m/Y')}–{$start->copy()->addMonthNoOverflow()->format('d/m/Y')}", 'amount_minor' => $plan->price_minor]],
            'buyer' => $this->buyer($org), 'period_start' => $start, 'period_end' => $start->copy()->addMonthNoOverflow(),
            'provider' => $this->provider()->name(), 'created_by' => $by?->id,
        ] + self::amounts($plan));
    }

    /** @return array{invoice: Invoice, url: string} */
    public function checkout(Organization $org, Plan $plan, User $by, string $returnUrl): array
    {
        if ($org->isSuspended()) {
            throw ApiException::conflict('organization_suspended', 'องค์กรนี้ถูกระงับการใช้งาน กรุณาติดต่อฝ่ายสนับสนุน');
        }
        if (! $plan->is_active || $plan->price_minor <= 0) {
            throw ApiException::unprocessable('plan_not_purchasable', 'แพ็กเกจนี้ซื้อไม่ได้');
        }
        $sub = Subscription::query()->where('organization_id', $org->id)->first();
        if ($sub && $sub->plan_id === $plan->id && $sub->status === 'active') {
            throw ApiException::conflict('already_subscribed', 'คุณใช้แพ็กเกจนี้อยู่แล้ว');
        }
        $provider = $this->provider();

        return DB::transaction(function () use ($org, $plan, $by, $returnUrl, $provider) {
            // Only one open checkout at a time: older ones are voided (their late webhooks are ignored).
            Invoice::query()->where('organization_id', $org->id)->where('kind', 'checkout')->where('status', 'open')->update(['status' => 'void']);
            $invoice = $this->newInvoice($org, $plan, 'checkout', now(), null, $by);
            $checkout = $provider->createCheckout($invoice, $returnUrl);
            $invoice->forceFill(['provider_ref' => $checkout['ref']])->save();
            $this->audit->record('billing.checkout_started', $org, ['plan' => $plan->code, 'invoice_id' => $invoice->id, 'total' => $invoice->total_minor], $org->id);

            return ['invoice' => $invoice, 'url' => $checkout['url']];
        });
    }

    public function startTrial(Organization $org, Plan $plan, User $by): Subscription
    {
        return DB::transaction(function () use ($org, $plan, $by) {
            $org = Organization::query()->whereKey($org->id)->lockForUpdate()->firstOrFail();
            if ($plan->trial_days <= 0 || $org->trial_used) {
                throw ApiException::unprocessable('trial_unavailable', 'ทดลองใช้ได้ครั้งเดียวต่อองค์กร และเฉพาะแพ็กเกจที่มีช่วงทดลอง');
            }
            if (Subscription::query()->where('organization_id', $org->id)->whereIn('status', ['active', 'past_due', 'trialing'])->exists()) {
                throw ApiException::conflict('already_subscribed', 'องค์กรนี้มีแพ็กเกจที่ใช้งานอยู่แล้ว');
            }
            $sub = Subscription::query()->updateOrCreate(['organization_id' => $org->id], [
                'plan_id' => $plan->id, 'status' => 'trialing', 'provider' => 'none', 'payment_method_ref' => null,
                'current_period_start' => now(), 'current_period_end' => now()->addDays($plan->trial_days),
                'cancel_at_period_end' => false, 'dunning_attempts' => 0, 'next_retry_at' => null, 'ended_at' => null,
            ]);
            $org->forceFill(['plan_id' => $plan->id, 'trial_used' => true])->save();
            $this->audit->record('billing.trial_started', $org, ['plan' => $plan->code, 'days' => $plan->trial_days], $org->id, null, $by->id);

            return $sub;
        });
    }

    private function nextInvoiceNumber(): string
    {
        $year = (int) now()->format('Y');
        DB::table('invoice_sequences')->insertOrIgnore(['year' => $year, 'last_number' => 0]);
        $row = DB::table('invoice_sequences')->where('year', $year)->lockForUpdate()->first();
        $next = $row->last_number + 1;
        DB::table('invoice_sequences')->where('year', $year)->update(['last_number' => $next]);

        return sprintf('INV-%d-%06d', $year, $next);
    }

    /** Called only from verified webhooks, inside a transaction holding the invoice lock. Idempotent. */
    public function markPaid(Invoice $invoice, string $paymentRef, ?string $methodRef): void
    {
        if ($invoice->status === 'paid') {
            return;
        }
        if ($invoice->status === 'void') {
            // Paid after being superseded: keep the money traceable for a manual refund, change nothing else.
            Payment::query()->firstOrCreate(['provider' => $invoice->provider, 'provider_ref' => $paymentRef], ['invoice_id' => $invoice->id, 'amount_minor' => $invoice->total_minor, 'currency' => $invoice->currency, 'status' => 'succeeded', 'failure_reason' => 'paid_void_invoice_refund_required']);
            $this->audit->record('billing.paid_void_invoice', null, ['invoice_id' => $invoice->id], $invoice->organization_id);

            return;
        }
        $org = Organization::query()->whereKey($invoice->organization_id)->lockForUpdate()->firstOrFail();
        Payment::query()->firstOrCreate(['provider' => $invoice->provider, 'provider_ref' => $paymentRef], ['invoice_id' => $invoice->id, 'amount_minor' => $invoice->total_minor, 'currency' => $invoice->currency, 'status' => 'succeeded']);
        $invoice->forceFill(['status' => 'paid', 'paid_at' => now(), 'number' => $this->nextInvoiceNumber()])->save();

        $sub = Subscription::query()->where('organization_id', $org->id)->lockForUpdate()->first();
        if ($invoice->kind === 'renewal' && $sub) {
            $sub->fill(['status' => 'active', 'current_period_start' => $invoice->period_start, 'current_period_end' => $invoice->period_end, 'dunning_attempts' => 0, 'next_retry_at' => null]);
        } else {
            $sub = $sub ?? new Subscription(['organization_id' => $org->id]);
            $sub->fill([
                'plan_id' => $invoice->plan_id, 'status' => 'active', 'provider' => $invoice->provider, 'payment_method_ref' => $methodRef ?? $sub->payment_method_ref,
                'current_period_start' => now(), 'current_period_end' => now()->addMonthNoOverflow(), 'cancel_at_period_end' => false,
                'dunning_attempts' => 0, 'next_retry_at' => null, 'ended_at' => null,
            ]);
            $invoice->forceFill(['subscription_id' => $sub->id ?? null]);
        }
        $sub->save();
        $invoice->forceFill(['subscription_id' => $sub->id])->save();
        $org->forceFill(['plan_id' => $invoice->plan_id])->save();
        $this->audit->record('billing.invoice_paid', $org, ['invoice' => $invoice->number, 'total' => $invoice->total_minor, 'plan_id' => $invoice->plan_id], $org->id);
        $this->notify($org, "ได้รับชำระเงินแล้ว ({$invoice->number})", 'ขอบคุณที่ชำระเงิน ใบเสร็จรับเงิน/ใบกำกับภาษีเลขที่ '.$invoice->number.' ดูได้ที่หน้า "การเรียกเก็บเงิน"');
    }

    public function markFailed(Invoice $invoice, string $paymentRef, string $reason): void
    {
        if ($invoice->status !== 'open') {
            return;
        }
        Payment::query()->firstOrCreate(['provider' => $invoice->provider, 'provider_ref' => $paymentRef !== '' ? $paymentRef : 'failed_'.$invoice->id.'_'.now()->timestamp], ['invoice_id' => $invoice->id, 'amount_minor' => $invoice->total_minor, 'currency' => $invoice->currency, 'status' => 'failed', 'failure_reason' => mb_substr($reason, 0, 200)]);
        $org = Organization::query()->findOrFail($invoice->organization_id);
        if ($invoice->kind !== 'renewal') {
            $this->audit->record('billing.checkout_failed', $org, ['invoice_id' => $invoice->id, 'reason' => $reason], $org->id);

            return;
        }
        $sub = Subscription::query()->where('organization_id', $org->id)->lockForUpdate()->firstOrFail();
        $days = config('studio.billing.dunning_days');
        $attempt = $sub->dunning_attempts + 1;
        if ($attempt > count($days)) {
            $invoice->forceFill(['status' => 'uncollectible'])->save();
            $this->downgrade($org, $sub, 'expired', 'ชำระค่าบริการไม่สำเร็จหลังลองเรียกเก็บหลายครั้ง');

            return;
        }
        $sub->fill(['status' => 'past_due', 'dunning_attempts' => $attempt, 'next_retry_at' => now()->addDays($days[$attempt - 1])])->save();
        $this->audit->record('billing.payment_failed', $org, ['invoice_id' => $invoice->id, 'attempt' => $attempt, 'reason' => $reason], $org->id);
        $this->notify($org, 'ชำระค่าบริการไม่สำเร็จ', "การตัดเงินค่าบริการไม่สำเร็จ ({$reason}) ระบบจะลองใหม่วันที่ ".$sub->next_retry_at->format('d/m/Y').' หากยังไม่สำเร็จ แพ็กเกจจะถูกปรับเป็น Free (ข้อมูลไม่ถูกลบ)');
    }

    /** Moves the organization to the free plan. Data is never deleted. */
    public function downgrade(Organization $org, Subscription $sub, string $status, string $reason): void
    {
        $sub->fill(['status' => $status, 'ended_at' => now(), 'next_retry_at' => null])->save();
        $org->forceFill(['plan_id' => $this->freePlan()->id])->save();
        $this->audit->record('billing.downgraded', $org, ['status' => $status, 'reason' => $reason], $org->id);
        $this->notify($org, 'แพ็กเกจถูกปรับเป็น Free', $reason.' — ข้อมูลทั้งหมดยังอยู่ครบ แต่การสร้างเพิ่มที่เกินโควตาของแพ็กเกจ Free จะถูกจำกัด จนกว่าจะอัปเกรดอีกครั้ง');
    }

    public function setCancelAtPeriodEnd(Organization $org, bool $cancel, User $by): Subscription
    {
        $sub = Subscription::query()->where('organization_id', $org->id)->whereIn('status', ['active', 'past_due', 'trialing'])->first()
            ?? throw ApiException::conflict('no_subscription', 'ไม่มีแพ็กเกจที่ชำระเงินอยู่');
        $sub->forceFill(['cancel_at_period_end' => $cancel])->save();
        $this->audit->record($cancel ? 'billing.cancel_scheduled' : 'billing.cancel_reverted', $org, [], $org->id, null, $by->id);

        return $sub;
    }

    /** Scheduler: trials ending, renewals, cancellations and dunning retries. */
    public function runDue(): array
    {
        $stats = ['renewals' => 0, 'retries' => 0, 'ended' => 0];
        Subscription::query()->whereIn('status', ['trialing', 'active'])->where('current_period_end', '<=', now())->limit(500)->get()
            ->each(function (Subscription $sub) use (&$stats) {
                $org = Organization::query()->find($sub->organization_id);
                if ($org === null) {
                    return;
                }
                if ($sub->status === 'trialing' || $sub->cancel_at_period_end) {
                    DB::transaction(fn () => $this->downgrade($org, $sub, $sub->status === 'trialing' ? 'expired' : 'canceled', $sub->status === 'trialing' ? 'ช่วงทดลองใช้สิ้นสุดแล้ว' : 'ยกเลิกแพ็กเกจตามที่ขอไว้'));
                    $stats['ended']++;

                    return;
                }
                $start = $sub->current_period_end->copy();
                $existing = Invoice::query()->where('subscription_id', $sub->id)->where('kind', 'renewal')->where('period_start', $start)->first();
                if ($existing) {
                    return; // already invoiced for this period; dunning handles retries
                }
                $plan = Plan::query()->findOrFail($sub->plan_id);
                $invoice = $this->newInvoice($org, $plan, 'renewal', $start, $sub, null);
                $invoice->forceFill(['provider_ref' => 'rn_'.$invoice->id])->save();
                $this->provider()->chargeRenewal($sub, $invoice);
                $stats['renewals']++;
            });
        Subscription::query()->where('status', 'past_due')->where('next_retry_at', '<=', now())->limit(500)->get()
            ->each(function (Subscription $sub) use (&$stats) {
                $invoice = Invoice::query()->where('subscription_id', $sub->id)->where('kind', 'renewal')->where('status', 'open')->latest('period_start')->first();
                if ($invoice) {
                    $sub->forceFill(['next_retry_at' => null])->save();
                    $this->provider()->chargeRenewal($sub, $invoice);
                    $stats['retries']++;
                }
            });

        return $stats;
    }

    private function notify(Organization $org, string $subject, string $body): void
    {
        $to = BillingProfile::query()->where('organization_id', $org->id)->value('email') ?? User::query()->whereKey($org->owner_id)->value('email');
        if ($to) {
            SendBillingEmail::dispatch($to, $subject, $body)->afterCommit();
        }
    }
}
