<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform operators (not tenants). Granted only via `php artisan studio:platform-admin`.
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('is_platform_admin')->default(false)->after('locale');
        });

        // Platform-wide settings. `value` is encrypted with the app key (JSON inside).
        Schema::create('platform_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key', 100)->unique();
            $t->text('value');
            $t->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('is_platform_admin'));
    }
};
