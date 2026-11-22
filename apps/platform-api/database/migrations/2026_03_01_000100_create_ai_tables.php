<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 3: AI gateway usage log, proposals awaiting approval, and BYOK credentials. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 16);          // plan | patch | repair
            $table->string('status', 16);        // queued | running | succeeded | failed
            $table->string('provider', 24)->nullable();
            $table->string('model', 64)->nullable();
            $table->boolean('fallback_used')->default(false);
            $table->boolean('consent_external')->default(false);
            $table->text('prompt_redacted');     // PII-masked, truncated
            $table->char('prompt_hash', 64);
            $table->json('context')->nullable(); // scope for patches (page/component ids)
            $table->json('result')->nullable();  // validated plan / edit spec
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cost_micro_usd')->default(0);
            $table->unsignedInteger('credits_reserved')->default(0);
            $table->unsignedInteger('credits_charged')->default(0);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('error_code', 40)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'created_at']);
            $table->index(['project_id', 'created_at']);
        });

        Schema::create('ai_proposals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('ai_request_id')->constrained('ai_requests')->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16);        // pending | applied | rejected | expired
            $table->unsignedInteger('base_revision');
            $table->unsignedInteger('applied_revision')->nullable();
            $table->json('commands');
            $table->json('summary');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['project_id', 'status']);
        });

        Schema::create('ai_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 24);
            $table->text('secret');              // encrypted (APP_KEY); never returned by the API
            $table->string('last4', 4);
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_credentials');
        Schema::dropIfExists('ai_proposals');
        Schema::dropIfExists('ai_requests');
    }
};
