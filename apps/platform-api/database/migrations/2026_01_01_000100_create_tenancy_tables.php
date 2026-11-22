<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->json('entitlements');
            $table->unsignedInteger('price_minor')->default(0);
            $table->string('currency', 3)->default('THB');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('organizations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug', 80)->unique();
            $table->foreignUlid('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->foreignUlid('owner_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('organization_members', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20); // owner | admin | billing_admin | member
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
        });

        Schema::create('workspaces', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug', 80);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'slug']);
        });

        Schema::create('workspace_members', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20); // admin | editor | developer | viewer
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_members');
        Schema::dropIfExists('workspaces');
        Schema::dropIfExists('organization_members');
        Schema::dropIfExists('organizations');
        Schema::dropIfExists('plans');
    }
};
