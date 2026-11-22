<?php

namespace Tests\Feature;

use App\Domain\Billing\BillingService;
use App\Domain\Billing\WebhookSignature;
use App\Jobs\SendBillingEmail;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProjectService;
use App\Support\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** M03 / J7: payment webhook → plan/quota update → usage enforcement → dunning → downgrade. */
class BillingTest extends TestCase
{
    private User $owner;

    private Workspace $ws;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([SendBillingEmail::class]);
        [$this->owner, $this->ws] = $this->tenant();
        $this->org = $this->ws->organization;
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/organizations/{$this->org->id}/billing{$path}";
    }

    private function checkout(string $plan = 'pro'): Invoice
    {
        $res = $this->actingAs($this->owner)->postJson($this->url('/checkout'), ['plan' => $plan])->assertCreated();
        $this->assertStringContainsString('/billing/test-checkout/cs_test_', $res->json('data.url'));

        return Invoice::query()->findOrFail($res->json('data.invoice.id'));
    }

    private function pay(Invoice $invoice, string $outcome = 'success')
    {
        return $this->actingAs($this->owner)->postJson("/api/v1/billing/test-checkout/{$invoice->provider_ref}/complete", ['outcome' => $outcome]);
    }

    private function webhook(array $event, ?int $t = null, ?string $secret = null)
    {
        $body = json_encode($event);
        $sig = WebhookSignature::sign($secret ?? (string) config('studio.billing.webhook_secret'), $body, $t ?? time());
        $this->app['auth']->forgetGuards();

        return $this->call('POST', '/api/v1/billing/webhooks/test', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_BILLING_SIGNATURE' => $sig, 'HTTP_ACCEPT' => 'application/json'], $body);
    }

    private function fillProjects(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            app(ProjectService::class)->create($this->ws, $this->owner, "โปรเจกต์ {$i}", '', null);
        }
    }

    public function test_j7_checkout_paid_by_webhook_upgrades_quota_then_dunning_downgrades_without_deleting_data(): void
    {
        $this->fillProjects(3); // free limit reached
        $this->actingAs($this->owner)->postJson("/api/v1/workspaces/{$this->ws->id}/projects", ['name' => 'เกินโควตา'])->assertStatus(422)->assertJsonPath('error.code', 'quota_exceeded');

        $invoice = $this->checkout();
        $this->assertSame(['subtotal' => 49000, 'vat' => 3430, 'total' => 52430], ['subtotal' => $invoice->subtotal_minor, 'vat' => $invoice->vat_minor, 'total' => $invoice->total_minor]);
        // Returning from checkout is NOT payment confirmation: nothing changes until the webhook.
        $this->assertSame('free', $this->org->fresh()->plan->code);

        $this->pay($invoice, 'success_decline_renewals')->assertOk();
        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertMatchesRegularExpression('/^INV-\d{4}-000001$/', $invoice->number);
        $this->assertSame('pro', $this->org->fresh()->plan->code);
        $sub = Subscription::query()->where('organization_id', $this->org->id)->sole();
        $this->assertSame('active', $sub->status);

        // Quota updated → usage allowed.
        $this->actingAs($this->owner)->postJson("/api/v1/workspaces/{$this->ws->id}/projects", ['name' => 'โปรเจกต์ที่ 4'])->assertCreated();
        $this->assertSame(4, Project::query()->where('organization_id', $this->org->id)->count());

        // Renewal fails (card declines) → past_due with retries per dunning schedule; still Pro meanwhile.
        $this->travelTo($sub->current_period_end->copy()->addMinute());
        $this->artisan('studio:billing:run')->assertSuccessful();
        $sub->refresh();
        $this->assertSame('past_due', $sub->status);
        $this->assertSame(1, $sub->dunning_attempts);
        $this->assertSame('pro', $this->org->fresh()->plan->code);
        $this->assertSame(1, Invoice::query()->where('kind', 'renewal')->count());
        $this->artisan('studio:billing:run'); // same period: no duplicate invoice, retry not due yet
        $this->assertSame(1, Invoice::query()->where('kind', 'renewal')->count());

        foreach ([1, 3, 7] as $days) {
            $this->travel($days)->days();
            $this->travel(1)->minutes();
            $this->artisan('studio:billing:run');
        }
        $sub->refresh();
        $this->assertSame('expired', $sub->status);
        $this->assertSame('free', $this->org->fresh()->plan->code);
        $this->assertSame('uncollectible', Invoice::query()->where('kind', 'renewal')->value('status'));
        $this->assertSame(4, Payment::query()->where('status', 'failed')->count());

        // Downgrade keeps data but blocks new usage above the free limits.
        $this->assertSame(4, Project::query()->where('organization_id', $this->org->id)->count());
        $this->actingAs($this->owner)->postJson("/api/v1/workspaces/{$this->ws->id}/projects", ['name' => 'อีกหนึ่ง'])->assertStatus(422)->assertJsonPath('error.code', 'quota_exceeded');
        Queue::assertPushed(SendBillingEmail::class, fn ($j) => str_contains($j->subject, 'Free'));
    }

    public function test_successful_renewal_extends_period_and_issues_next_invoice_number(): void
    {
        $this->pay($this->checkout())->assertOk();
        $sub = Subscription::query()->sole();
        $end = $sub->current_period_end->copy();
        $this->travelTo($end->copy()->addMinute());
        $this->artisan('studio:billing:run');
        $sub->refresh();
        $this->assertSame('active', $sub->status);
        $this->assertTrue($sub->current_period_end->greaterThan($end->copy()->addDays(27)));
        $this->assertSame(['INV-'.now()->format('Y').'-000001', 'INV-'.now()->format('Y').'-000002'], Invoice::query()->whereNotNull('number')->orderBy('number')->pluck('number')->all());
    }

    public function test_webhooks_require_valid_signature_reject_replays_and_are_idempotent(): void
    {
        $invoice = $this->checkout();
        $event = ['id' => 'evt_1', 'type' => 'checkout.completed', 'data' => ['checkoutRef' => $invoice->provider_ref, 'paymentRef' => 'pay_1', 'amount' => $invoice->total_minor, 'currency' => 'THB', 'paymentMethodRef' => 'tok_test_ok']];

        $this->webhook($event, secret: 'wrong-secret')->assertStatus(400)->assertJsonPath('error.code', 'invalid_signature');
        $this->webhook($event, time() - 3600)->assertStatus(400)->assertJsonPath('error.code', 'stale_webhook');
        $this->assertSame('free', $this->org->fresh()->plan->code);

        // Amount tampering is recorded and rejected.
        $bad = $event;
        $bad['id'] = 'evt_bad';
        $bad['data']['amount'] = 100;
        $this->webhook($bad)->assertOk()->assertJsonPath('received', 'rejected');
        $this->assertSame('open', $invoice->fresh()->status);

        $this->webhook($event)->assertOk()->assertJsonPath('received', 'processed');
        $this->webhook($event)->assertOk()->assertJsonPath('received', 'duplicate');
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(1, Payment::query()->count());
        // Only signature-verified events are stored; the duplicate was not inserted again.
        $this->assertSame(['rejected', 'processed'], DB::table('billing_webhook_events')->orderBy('id')->pluck('status')->all());
    }

    public function test_declined_checkout_changes_nothing_and_superseded_checkouts_are_void(): void
    {
        $first = $this->checkout();
        $second = $this->checkout('business');
        $this->assertSame('void', $first->fresh()->status);
        $this->pay($second, 'decline')->assertOk();
        $this->assertSame('open', $second->fresh()->status);
        $this->assertSame('free', $this->org->fresh()->plan->code);
        $this->pay($first)->assertStatus(409);
    }

    public function test_trial_once_then_expires_to_free(): void
    {
        $this->actingAs($this->owner)->postJson($this->url('/trial'), ['plan' => 'business'])->assertStatus(422);
        $this->actingAs($this->owner)->postJson($this->url('/trial'), ['plan' => 'pro'])->assertOk()->assertJsonPath('data.subscription.status', 'trialing')->assertJsonPath('data.currentPlan', 'pro');
        $this->actingAs($this->owner)->postJson($this->url('/trial'), ['plan' => 'pro'])->assertStatus(422);
        $this->travel(15)->days();
        $this->artisan('studio:billing:run');
        $this->assertSame('free', $this->org->fresh()->plan->code);
        $this->assertSame('expired', Subscription::query()->value('status'));
    }

    public function test_cancel_at_period_end_and_resume(): void
    {
        $this->pay($this->checkout())->assertOk();
        $this->actingAs($this->owner)->postJson($this->url('/cancel'))->assertOk()->assertJsonPath('data.subscription.cancelAtPeriodEnd', true);
        $this->actingAs($this->owner)->postJson($this->url('/cancel'), ['resume' => true])->assertOk()->assertJsonPath('data.subscription.cancelAtPeriodEnd', false);
        $this->actingAs($this->owner)->postJson($this->url('/cancel'))->assertOk();
        $this->travel(32)->days();
        $this->artisan('studio:billing:run');
        $this->assertSame('canceled', Subscription::query()->value('status'));
        $this->assertSame('free', $this->org->fresh()->plan->code);
        $this->assertSame(0, Invoice::query()->where('kind', 'renewal')->count());
    }

    public function test_tax_invoice_snapshot_print_and_access_control(): void
    {
        $this->actingAs($this->owner)->putJson($this->url('/profile'), ['legalName' => 'บริษัท ตัวอย่าง จำกัด', 'taxId' => '0105500000001', 'branch' => 'สำนักงานใหญ่', 'address' => 'กรุงเทพฯ', 'email' => 'acc@example.com'])->assertOk();
        $this->actingAs($this->owner)->putJson($this->url('/profile'), ['legalName' => 'x', 'taxId' => '123', 'address' => 'x', 'email' => 'acc@example.com'])->assertStatus(422);
        $invoice = $this->checkout();
        $this->pay($invoice)->assertOk();
        $html = $this->actingAs($this->owner)->get("/api/v1/organizations/{$this->org->id}/invoices/{$invoice->id}/print")->assertOk()->getContent();
        $this->assertStringContainsString('ใบเสร็จรับเงิน / ใบกำกับภาษี', $html);
        $this->assertStringContainsString('0105500000001', $html);
        $this->assertStringContainsString('524.30', $html);
        $this->assertStringContainsString('โหมดทดสอบ', $html);

        $member = User::factory()->create();
        $this->org->members()->attach($member->id, ['role' => 'member']);
        $this->actingAs($member)->getJson($this->url())->assertForbidden();
        [$stranger] = $this->tenant('Stranger');
        $this->actingAs($stranger)->getJson($this->url())->assertNotFound();
        $this->actingAs($stranger)->postJson("/api/v1/billing/test-checkout/{$invoice->provider_ref}/complete", ['outcome' => 'success'])->assertNotFound();
    }

    public function test_test_provider_is_refused_in_production(): void
    {
        $this->app['env'] = 'production';
        try {
            app(BillingService::class)->provider();
            $this->fail('test provider must be refused in production');
        } catch (ApiException $e) {
            $this->assertSame('billing_not_activated', $e->errorCode);
        } finally {
            $this->app['env'] = 'testing';
        }
        config(['studio.billing.provider' => 'omise']); // no adapter exists → not activated
        $this->actingAs($this->owner)->postJson($this->url('/checkout'), ['plan' => 'pro'])->assertStatus(503)->assertJsonPath('error.code', 'billing_not_activated');
        $this->assertSame(52430, BillingService::amounts(Plan::query()->where('code', 'pro')->first())['total_minor']);
    }
}
