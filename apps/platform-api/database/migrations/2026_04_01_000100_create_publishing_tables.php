<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 4: immutable releases, deployments, hostnames, preview links, generated-app end users. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('releases', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedInteger('source_revision');
            $table->json('document');           // frozen, validated snapshot (never updated)
            $table->json('manifest');           // routes, assets, hashes, smoke-test results
            $table->char('content_hash', 64);
            $table->string('status', 16);       // ready | failed
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['project_id', 'version']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignUlid('live_release_id')->nullable()->after('template_id')->constrained('releases')->nullOnDelete();
            $table->timestamp('published_at')->nullable()->after('live_release_id');
        });

        Schema::create('deployments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('release_id')->nullable()->constrained('releases')->nullOnDelete();
            $table->foreignUlid('previous_release_id')->nullable()->constrained('releases')->nullOnDelete();
            $table->string('action', 16);       // publish | rollback | unpublish
            $table->string('status', 16);       // queued | running | succeeded | failed
            $table->unsignedInteger('source_revision')->nullable();
            $table->json('steps');              // [{step, status, message, at}]
            $table->string('error_code', 40)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'created_at']);
        });

        // Platform subdomains and custom domains share one table: a hostname maps to at most one project.
        Schema::create('domains', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('hostname', 253);
            $table->string('type', 12);         // subdomain | custom
            $table->string('status', 24);       // active | pending_verification | verified | failed | released
            $table->string('verification_token', 64)->nullable();
            $table->json('checks')->nullable(); // last DNS/TLS check results
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('tls_active_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->string('active_hostname', 253)->nullable()->unique(); // = hostname while not released
            $table->timestamps();
            $table->index('hostname');
        });

        Schema::create('preview_links', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->unsignedInteger('revision');
            $table->json('document');           // snapshot at creation (links never show later edits)
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('app_users', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('environment', 12);
            $table->string('email');
            $table->string('name', 120);
            $table->string('password');
            $table->string('role', 12)->default('member'); // member | admin
            $table->string('status', 12)->default('active'); // active | disabled
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'environment', 'email']);
        });

        Schema::create('app_user_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('app_user_id')->constrained('app_users')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('app_records', function (Blueprint $table) {
            $table->ulid('app_user_id')->nullable()->after('created_by')->index(); // owner, for future "own" rules
        });
    }

    public function down(): void
    {
        Schema::table('app_records', fn (Blueprint $t) => $t->dropColumn('app_user_id'));
        Schema::dropIfExists('app_user_sessions');
        Schema::dropIfExists('app_users');
        Schema::dropIfExists('preview_links');
        Schema::dropIfExists('domains');
        Schema::dropIfExists('deployments');
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('live_release_id');
            $table->dropColumn('published_at');
        });
        Schema::dropIfExists('releases');
    }
};
