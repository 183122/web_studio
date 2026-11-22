<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // M22: one Google connection per workspace, granted explicitly by a workspace admin.
        Schema::create('google_connections', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('google_sub', 64)->nullable();
            $t->string('email', 190)->nullable();
            $t->json('scopes');
            $t->text('access_token')->nullable();   // encrypted
            $t->text('refresh_token')->nullable();  // encrypted
            $t->timestamp('expires_at')->nullable();
            $t->string('status', 20)->default('active'); // active | revoked | error
            $t->string('last_error', 300)->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();
            $t->unique('workspace_id');
        });

        // M23: a model ↔ spreadsheet tab mapping.
        Schema::create('sheet_syncs', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $t->string('environment', 20)->default('production');
            $t->string('model_id', 64);
            $t->string('spreadsheet_id', 128);
            $t->string('spreadsheet_title', 200)->nullable();
            $t->string('sheet_title', 100);
            $t->json('mapping');                    // [{fieldId, column}]
            $t->string('key_field_id', 64)->nullable(); // idempotent upsert key for pulls
            $t->string('direction', 10);            // pull | push | both
            $t->string('conflict_policy', 20)->default('app_wins'); // app_wins | sheet_wins | skip
            $t->unsignedSmallInteger('interval_minutes')->default(0); // 0 = manual only
            $t->string('status', 20)->default('active'); // active | paused | disconnected
            $t->timestamp('last_synced_at')->nullable();
            $t->timestamp('next_run_at')->nullable();
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['status', 'next_run_at']);
        });

        // Row identity + last-seen hashes for conflict detection.
        Schema::create('sheet_sync_rows', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('sync_id')->constrained('sheet_syncs')->cascadeOnDelete();
            $t->ulid('record_id');
            $t->string('app_hash', 64);
            $t->string('sheet_hash', 64);
            $t->timestamps();
            $t->unique(['sync_id', 'record_id']);
        });

        Schema::create('integration_sync_jobs', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('sync_id')->nullable()->constrained('sheet_syncs')->cascadeOnDelete();
            $t->string('kind', 20);                 // pull | push | both
            $t->string('trigger', 20);              // manual | schedule | retry
            $t->string('status', 20);               // queued | running | succeeded | partial | failed | dead
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->json('stats')->nullable();
            $t->json('errors')->nullable();
            $t->string('error', 400)->nullable();
            $t->foreignUlid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->index(['project_id', 'created_at']);
        });

        // M24: per-project Drive folder + file references attached to records.
        Schema::create('drive_folders', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('project_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('folder_id', 128);
            $t->string('name', 200);
            $t->timestamps();
        });
        Schema::create('record_attachments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $t->ulid('record_id')->index();
            $t->string('drive_file_id', 128);
            $t->string('name', 255);
            $t->string('mime', 120);
            $t->unsignedBigInteger('size');
            $t->string('status', 20)->default('available'); // available | missing | access_lost
            $t->foreignUlid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('checked_at')->nullable();
            $t->timestamps();
        });

        // M25: outbound webhooks for app data events.
        Schema::create('webhook_endpoints', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $t->string('environment', 20)->default('production');
            $t->string('url', 500);
            $t->json('events');
            $t->text('secret');                     // encrypted
            $t->string('description', 200)->default('');
            $t->boolean('enabled')->default(true);
            $t->unsignedInteger('consecutive_failures')->default(0);
            $t->string('disabled_reason', 200)->nullable();
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('webhook_deliveries', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('endpoint_id')->constrained('webhook_endpoints')->cascadeOnDelete();
            $t->ulid('event_id');
            $t->string('event', 40);
            $t->json('payload');
            $t->string('status', 20);               // pending | succeeded | failed | dead
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->unsignedSmallInteger('response_status')->nullable();
            $t->string('error', 400)->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamps();
            $t->index(['endpoint_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['webhook_deliveries', 'webhook_endpoints', 'record_attachments', 'drive_folders', 'integration_sync_jobs', 'sheet_sync_rows', 'sheet_syncs', 'google_connections'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
