<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $t) {
            $t->string('source', 20)->default('builtin')->after('category');      // builtin | community
            $t->foreignUlid('author_org_id')->nullable()->after('source')->constrained('organizations')->nullOnDelete();
            $t->string('author_name', 120)->nullable()->after('author_org_id');
            $t->ulid('source_project_id')->nullable()->after('author_name');
            $t->string('license', 30)->default('free')->after('source_project_id'); // free | personal
            $t->string('delisted_reason', 300)->nullable()->after('is_published');
            $t->unsignedInteger('install_count')->default(0)->after('delisted_reason');
        });
        Schema::table('template_versions', function (Blueprint $t) {
            $t->json('assets')->nullable();       // files copied into template storage at approval
            $t->string('changelog', 500)->nullable();
        });
        Schema::create('template_submissions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('template_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('project_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUlid('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('name', 80);
            $t->string('description', 500);
            $t->string('category', 40);
            $t->string('license', 30);
            $t->string('changelog', 500)->nullable();
            $t->json('document');
            $t->json('assets');                    // [{ref, path, mime, size, width, height, name, sha256}]
            $t->json('sanitization')->nullable();  // what was removed, shown to moderators
            $t->string('status', 20);              // pending | approved | rejected | withdrawn
            $t->foreignUlid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('review_reason', 500)->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_submissions');
        Schema::table('template_versions', fn (Blueprint $t) => $t->dropColumn(['assets', 'changelog']));
        Schema::table('templates', function (Blueprint $t) {
            $t->dropConstrainedForeignId('author_org_id');
            $t->dropColumn(['source', 'author_name', 'source_project_id', 'license', 'delisted_reason', 'install_count']);
        });
    }
};
