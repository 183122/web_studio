<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Support access: requested by a platform admin, approved by the organization; read-only, expiring.
        Schema::create('support_grants', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('requested_by')->constrained('users')->cascadeOnDelete();
            $t->string('reason', 500);
            $t->unsignedSmallInteger('minutes');
            $t->string('status', 20); // pending | approved | denied | revoked | expired
            $t->foreignUlid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
            $t->index(['requested_by', 'status', 'expires_at']);
        });

        Schema::create('abuse_reports', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('url', 500);
            $t->string('host', 253)->nullable();
            $t->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete();
            $t->string('category', 30); // phishing | malware | spam | illegal | other
            $t->text('details');
            $t->string('reporter_email', 190)->nullable();
            $t->string('reporter_ip_hash', 64)->nullable();
            $t->string('status', 20)->default('open'); // open | actioned | dismissed
            $t->foreignUlid('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('resolution', 500)->nullable();
            $t->timestamps();
            $t->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abuse_reports');
        Schema::dropIfExists('support_grants');
    }
};
