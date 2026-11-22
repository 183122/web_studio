<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\BillingService;
use App\Domain\Billing\BillingWebhookProcessor;
use App\Domain\Billing\TestPaymentProvider;
use App\Http\Controllers\Controller;
use App\Models\BillingProfile;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\AuditLogger;
use App\Services\EntitlementService;
use App\Support\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** M03 billing: plans, subscription, profile, checkout, invoices, provider webhooks. */
class BillingController extends Controller
{
    public function __construct(private readonly BillingService $billing, private readonly EntitlementService $entitlements, private readonly AuditLogger $audit) {}

    public static function presentInvoice(Invoice $i): array
    {
        return [
            'id' => $i->id, 'number' => $i->number, 'kind' => $i->kind, 'status' => $i->status, 'currency' => $i->currency,
            'subtotal' => $i->subtotal_minor, 'vat' => $i->vat_minor, 'total' => $i->total_minor,
            'periodStart' => $i->period_start?->toIso8601String(), 'periodEnd' => $i->period_end?->toIso8601String(),
            'paidAt' => $i->paid_at?->toIso8601String(), 'createdAt' => $i->created_at?->toIso8601String(),
            'planName' => Plan::query()->whereKey($i->plan_id)->value('name'),
        ];
    }

    public function show(Request $request, Organization $organization): JsonResponse
    {
        $this->authorize('billing', $organization);
        $sub = Subscription::query()->where('organization_id', $organization->id)->first();
        $profile = BillingProfile::query()->where('organization_id', $organization->id)->first();
        $provider = config('studio.billing.provider');

        return response()->json(['data' => [
            'organization' => ['id' => $organization->id, 'name' => $organization->name, 'status' => $organization->status, 'trialUsed' => (bool) $organization->trial_used],
            'currentPlan' => $organization->plan?->code,
            'plans' => Plan::query()->where('is_active', true)->orderBy('sort')->get()->map(fn (Plan $p) => [
                'code' => $p->code, 'name' => $p->name, 'price' => $p->price_minor, 'currency' => $p->currency, 'trialDays' => $p->trial_days,
                'entitlements' => $p->entitlements,
            ] + ['amounts' => BillingService::amounts($p)]),
            'subscription' => $sub ? [
                'plan' => Plan::query()->whereKey($sub->plan_id)->value('code'), 'status' => $sub->status,
                'currentPeriodEnd' => $sub->current_period_end?->toIso8601String(), 'cancelAtPeriodEnd' => $sub->cancel_at_period_end,
                'nextRetryAt' => $sub->next_retry_at?->toIso8601String(), 'dunningAttempts' => $sub->dunning_attempts,
            ] : null,
            'usage' => $this->entitlements->usage($organization),
            'profile' => $profile ? ['legalName' => $profile->legal_name, 'taxId' => $profile->tax_id, 'branch' => $profile->branch, 'address' => $profile->address, 'email' => $profile->email] : null,
            'invoices' => Invoice::query()->where('organization_id', $organization->id)->where('status', '!=', 'void')->latest('created_at')->latest('id')->limit(24)->get()->map(fn ($i) => self::presentInvoice($i)),
            'provider' => ['name' => $provider, 'testMode' => $provider === 'test', 'activated' => $provider !== 'test' || ! app()->isProduction()],
        ]]);
    }

    public function updateProfile(Request $request, Organization $organization): JsonResponse
    {
        $this->authorize('billing', $organization);
        $d = $request->validate([
            'legalName' => ['required', 'string', 'max:200'],
            'taxId' => ['nullable', 'digits:13'],
            'branch' => ['nullable', 'string', 'max:60'],
            'address' => ['required', 'string', 'max:500'],
            'email' => ['required', 'email:rfc', 'max:190'],
        ]);
        BillingProfile::query()->updateOrCreate(['organization_id' => $organization->id], [
            'legal_name' => $d['legalName'], 'tax_id' => $d['taxId'] ?? null, 'branch' => $d['branch'] ?? null, 'address' => $d['address'], 'email' => $d['email'],
        ]);
        $this->audit->record('billing.profile_updated', $organization, [], $organization->id);

        return $this->show($request, $organization);
    }

    public function checkout(Request $request, Organization $organization): JsonResponse
    {
        $this->authorize('billing', $organization);
        $d = $request->validate(['plan' => ['required', 'string', Rule::exists('plans', 'code')]]);
        $plan = Plan::query()->where('code', $d['plan'])->firstOrFail();
        $res = $this->billing->checkout($organization, $plan, $request->user(), rtrim((string) config('studio.frontend_url'), '/').'/app/billing?checkout=return');

        return response()->json(['data' => ['url' => $res['url'], 'invoice' => self::presentInvoice($res['invoice'])]], 201);
    }

    public function trial(Request $request, Organization $organization): JsonResponse
    {
        $this->authorize('billing', $organization);
        $d = $request->validate(['plan' => ['required', 'string', Rule::exists('plans', 'code')]]);
        $this->billing->startTrial($organization, Plan::query()->where('code', $d['plan'])->firstOrFail(), $request->user());

        return $this->show($request, $organization->fresh());
    }

    public function cancel(Request $request, Organization $organization): JsonResponse
    {
        $this->authorize('billing', $organization);
        $this->billing->setCancelAtPeriodEnd($organization, $request->boolean('resume') === false, $request->user());

        return $this->show($request, $organization);
    }

    private function invoice(Organization $org, string $id): Invoice
    {
        return Invoice::query()->where('organization_id', $org->id)->whereKey($id)->firstOr(fn () => throw new ApiException(404, 'not_found', 'ไม่พบใบแจ้งหนี้'));
    }

    public function showInvoice(Organization $organization, string $id): JsonResponse
    {
        $this->authorize('billing', $organization);

        return response()->json(['data' => self::presentInvoice($this->invoice($organization, $id))]);
    }

    /** Printable invoice / tax invoice (HTML; print to PDF from the browser). */
    public function printInvoice(Organization $organization, string $id): Response
    {
        $this->authorize('billing', $organization);
        $i = $this->invoice($organization, $id);
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $money = fn (int $minor) => number_format($minor / 100, 2);
        $seller = config('studio.billing.seller');
        $buyer = $i->buyer;
        $title = $i->status === 'paid' ? 'ใบเสร็จรับเงิน / ใบกำกับภาษี' : 'ใบแจ้งหนี้ (ยังไม่ชำระ)';
        $rows = implode('', array_map(fn ($l) => '<tr><td>'.$e($l['description']).'</td><td class="r">'.$money($l['amount_minor']).'</td></tr>', $i->lines));
        $html = '<!doctype html><html lang="th"><head><meta charset="utf-8"><title>'.$e($title.' '.($i->number ?? '')).'</title>'
            .'<style>body{font-family:system-ui,sans-serif;max-width:760px;margin:2rem auto;color:#0f172a}table{width:100%;border-collapse:collapse}td,th{padding:6px;border-bottom:1px solid #e2e8f0;text-align:left}.r{text-align:right}h1{font-size:1.4rem}.muted{color:#64748b;font-size:.85rem}@media print{.noprint{display:none}}</style></head><body>'
            .'<h1>'.$e($title).'</h1>'
            .($i->number ? '<p>เลขที่ <b>'.$e($i->number).'</b> · วันที่ '.$e($i->paid_at?->format('d/m/Y')).'</p>' : '<p class="muted">อ้างอิง '.$e($i->id).'</p>')
            .'<h3>ผู้ขาย</h3><p>'.$e($seller['name']).'<br>เลขประจำตัวผู้เสียภาษี '.$e($seller['tax_id'] ?: '—').'<br>'.nl2br($e($seller['address'])).'</p>'
            .'<h3>ผู้ซื้อ</h3><p>'.($buyer ? $e($buyer['legalName']).'<br>เลขประจำตัวผู้เสียภาษี '.$e($buyer['taxId'] ?: '—').($buyer['branch'] ? ' ('.$e($buyer['branch']).')' : '').'<br>'.nl2br($e($buyer['address'])) : $e($organization->name)).'</p>'
            .'<table><thead><tr><th>รายการ</th><th class="r">จำนวนเงิน ('.$e($i->currency).')</th></tr></thead><tbody>'.$rows
            .'<tr><td class="r">รวมก่อนภาษี</td><td class="r">'.$money($i->subtotal_minor).'</td></tr>'
            .'<tr><td class="r">ภาษีมูลค่าเพิ่ม '.($i->vat_rate_bp / 100).'%</td><td class="r">'.$money($i->vat_minor).'</td></tr>'
            .'<tr><td class="r"><b>รวมทั้งสิ้น</b></td><td class="r"><b>'.$money($i->total_minor).'</b></td></tr></tbody></table>'
            .($i->provider === 'test' ? '<p class="muted">เอกสารจากโหมดทดสอบ — ไม่มีการชำระเงินจริงและไม่ใช้เป็นหลักฐานทางภาษี</p>' : '')
            .'<p class="noprint"><button onclick="window.print()">พิมพ์ / บันทึกเป็น PDF</button></p></body></html>';

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-hashes' 'sha256-".base64_encode(hash('sha256', 'window.print()', true))."'"]);
    }

    /** Provider webhooks: raw body + signature header; unauthenticated by design. */
    public function webhook(Request $request, string $provider, BillingWebhookProcessor $processor): JsonResponse
    {
        $headers = array_map(fn ($v) => $v[0] ?? null, $request->headers->all());

        return response()->json(['received' => $processor->receive($provider, $request->getContent(), $headers)]);
    }

    // ------------------------------------------------------------ test provider hosted checkout

    private function testInvoice(string $ref): array
    {
        $provider = $this->billing->provider();
        if (! $provider instanceof TestPaymentProvider) {
            throw new ApiException(404, 'not_found', 'ไม่พบหน้าชำระเงิน');
        }
        $invoice = Invoice::query()->where('provider', 'test')->where('provider_ref', $ref)->firstOr(fn () => throw new ApiException(404, 'not_found', 'ไม่พบรายการชำระเงิน'));

        return [$provider, $invoice, Organization::query()->findOrFail($invoice->organization_id)];
    }

    public function testCheckout(string $ref): JsonResponse
    {
        [, $invoice, $org] = $this->testInvoice($ref);
        $this->authorize('billing', $org);

        return response()->json(['data' => self::presentInvoice($invoice) + ['organization' => $org->name]]);
    }

    public function testCheckoutComplete(Request $request, string $ref): JsonResponse
    {
        [$provider, $invoice, $org] = $this->testInvoice($ref);
        $this->authorize('billing', $org);
        $d = $request->validate(['outcome' => ['required', Rule::in(['success', 'success_decline_renewals', 'decline'])]]);
        if ($invoice->status !== 'open') {
            throw ApiException::conflict('invoice_not_open', 'รายการนี้ปิดไปแล้ว');
        }
        $provider->completeCheckout($invoice, $d['outcome']);

        return response()->json(['data' => ['ok' => true]]);
    }
}
