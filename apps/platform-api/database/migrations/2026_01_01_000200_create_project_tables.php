<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('slug', 80)->unique();
            $table->string('name');
            $table->text('description');
            $table->string('category', 40);
            $table->boolean('is_published')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->ulid('current_version_id')->nullable();
            $table->timestamps();
        });

        Schema::create('template_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('template_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('document');
            $table->char('content_hash', 64);
            $table->timestamp('created_at')->nullable();
            $table->unique(['template_id', 'version']);
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('slug', 80);
            $table->string('description', 500)->default('');
            $table->string('status', 20)->default('active'); // active | archived
            $table->foreignUlid('template_id')->nullable()->constrained('templates')->nullOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['workspace_id', 'slug']);
            $table->index(['workspace_id', 'status', 'updated_at']);
        });

        Schema::create('project_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['user_id', 'project_id']);
        });

        // Head document of each project (optimistic concurrency via current_revision).
        Schema::create('app_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('current_revision');
            $table->unsignedInteger('schema_version');
            $table->json('document');
            $table->char('content_hash', 64);
            $table->unsignedInteger('size_bytes');
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Immutable checkpoints (snapshots). Not every revision has one; see ADR-0004.
        Schema::create('app_revisions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->json('document');
            $table->char('content_hash', 64);
            $table->unsignedInteger('size_bytes');
            $table->string('source', 20); // template | blank | editor | restore | api | clone | ai
            $table->string('message', 200)->nullable();
            $table->unsignedInteger('restored_from_revision')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['project_id', 'revision']);
        });

        // Append-only command log: every accepted command, in order.
        Schema::create('app_change_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->unsignedSmallInteger('seq');
            $table->string('command_type', 40);
            $table->json('payload');
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['project_id', 'revision', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_change_events');
        Schema::dropIfExists('app_revisions');
        Schema::dropIfExists('app_definitions');
        Schema::dropIfExists('project_favorites');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('template_versions');
        Schema::dropIfExists('templates');
    }
};
