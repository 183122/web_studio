<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $t) {
            $t->unsignedSmallInteger('trial_days')->default(0)->after('currency');
            $t->unsignedSmallInteger('sort')->default(0)->after('trial_days');
        });
        Schema::table('organizations', function (Blueprint $t) {
            $t->string('status', 20)->default('active')->after('owner_id'); // active | suspended
            $t->string('suspended_reason', 300)->nullable()->after('status');
            $t->timestamp('suspended_at')->nullable()->after('suspended_reason');
            $t->boolean('trial_used')->default(false)->after('suspended_at');
        });

        // Buyer details printed on tax invoices (snapshotted into each invoice).
        Schema::create('billing_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('organization_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('legal_name', 200);
            $t->string('tax_id', 13)->nullable();
            $t->string('branch', 60)->nullable();      // e.g. สำนักงานใหญ่ / สาขาที่ 00001
            $t->text('address');
            $t->string('email', 190);
            $t->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->unique()->constrained()->cascadeOnDelete();
            $t->foreignUlid('plan_id')->constrained('plans');
            $t->string('status', 20);                  // trialing | active | past_due | canceled | expired
            $t->string('provider', 30);
            $t->string('payment_method_ref', 120)->nullable();
            $t->timestamp('current_period_start')->nullable();
            $t->timestamp('current_period_end')->nullable();
            $t->boolean('cancel_at_period_end')->default(false);
            $t->unsignedTinyInteger('dunning_attempts')->default(0);
            $t->timestamp('next_retry_at')->nullable();
            $t->timestamp('ended_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'current_period_end']);
        });

        Schema::create('invoices', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('subscription_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUlid('plan_id')->constrained('plans');
            $t->string('number', 30)->nullable()->unique(); // assigned on payment (tax invoice number)
            $t->string('kind', 20);                    // checkout | renewal
            $t->string('status', 20);                  // open | paid | void | uncollectible
            $t->string('currency', 3);
            $t->unsignedInteger('subtotal_minor');
            $t->unsignedSmallInteger('vat_rate_bp');
            $t->unsignedInteger('vat_minor');
            $t->unsignedInteger('total_minor');
            $t->json('lines');
            $t->json('buyer')->nullable();
            $t->timestamp('period_start');
            $t->timestamp('period_end');
            $t->string('provider', 30);
            $t->string('provider_ref', 120)->nullable()->unique();
            $t->timestamp('paid_at')->nullable();
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['organization_id', 'created_at']);
        });

        Schema::create('invoice_sequences', function (Blueprint $t) {
            $t->unsignedSmallInteger('year')->primary();
            $t->unsignedInteger('last_number')->default(0);
        });

        Schema::create('payments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('invoice_id')->constrained()->cascadeOnDelete();
            $t->string('provider', 30);
            $t->string('provider_ref', 120);
            $t->unsignedInteger('amount_minor');
            $t->string('currency', 3);
            $t->string('status', 20);                  // succeeded | failed
            $t->string('failure_reason', 200)->nullable();
            $t->timestamps();
            $t->unique(['provider', 'provider_ref']);
        });

        // Every signature-verified provider webhook: idempotency (unique event id) + audit trail.
        Schema::create('billing_webhook_events', function (Blueprint $t) {
            $t->id();
            $t->string('provider', 30);
            $t->string('event_id', 120);
            $t->string('type', 60);
            $t->json('payload');
            $t->string('status', 20);                  // processed | ignored | rejected
            $t->string('note', 300)->nullable();
            $t->timestamp('processed_at')->nullable();
            $t->timestamps();
            $t->unique(['provider', 'event_id']);
        });
    }

    public function down(): void
    {
        foreach (['billing_webhook_events', 'payments', 'invoice_sequences', 'invoices', 'subscriptions', 'billing_profiles'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn(['status', 'suspended_reason', 'suspended_at', 'trial_used']));
        Schema::table('plans', fn (Blueprint $t) => $t->dropColumn(['trial_days', 'sort']));
    }
};
