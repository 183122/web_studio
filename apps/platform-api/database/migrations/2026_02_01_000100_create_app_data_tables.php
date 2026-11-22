<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * App data plane (Phase 2). Every row is scoped by project_id + environment; model/field ids come
 * from the project's AppDefinition. Preview data is kept separate from production (Phase 4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('environment', 12); // preview | production
            $table->string('model_id', 64);
            $table->json('data');
            $table->unsignedInteger('version')->default(1);
            $table->string('source', 16); // studio | form | api | import | workflow
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['project_id', 'environment', 'model_id', 'deleted_at', 'created_at'], 'app_records_scope_idx');
        });

        // Enforces unique fields atomically (one row per unique value).
        Schema::create('app_record_unique_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('environment', 12);
            $table->string('model_id', 64);
            $table->string('field_id', 64);
            $table->char('value_hash', 64);
            $table->foreignUlid('record_id')->constrained('app_records')->cascadeOnDelete();
            $table->unique(['project_id', 'environment', 'model_id', 'field_id', 'value_hash'], 'app_unique_value');
            $table->index('record_id');
        });

        Schema::create('app_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('environment', 12);
            $table->string('model_id', 64);
            $table->ulid('record_id')->nullable();
            $table->string('action', 40);
            $table->string('actor_type', 16); // user | public | member | admin | api_key | workflow
            $table->string('actor_id', 64)->nullable();
            $table->json('changes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['project_id', 'environment', 'model_id', 'created_at'], 'app_audit_scope_idx');
            $table->index('record_id');
        });

        Schema::create('app_api_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('environment', 12);
            $table->string('name', 80);
            $table->string('prefix', 16)->unique();
            $table->char('key_hash', 64);
            $table->string('role', 12); // public | member | admin (app role the key acts as)
            $table->json('scopes');
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('environment', 12);
            $table->string('workflow_id', 64);
            $table->ulid('record_id');
            $table->string('event', 32);
            $table->string('event_key', 100);
            $table->string('status', 16); // running | succeeded | skipped | failed
            $table->json('steps');
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'workflow_id', 'record_id', 'event_key'], 'workflow_run_once');
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_runs');
        Schema::dropIfExists('app_api_keys');
        Schema::dropIfExists('app_audit_logs');
        Schema::dropIfExists('app_record_unique_keys');
        Schema::dropIfExists('app_records');
    }
};
