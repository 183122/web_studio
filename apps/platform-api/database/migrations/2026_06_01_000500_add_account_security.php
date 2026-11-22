<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->text('two_factor_secret')->nullable();          // encrypted
            $t->text('two_factor_recovery_codes')->nullable();  // encrypted JSON of SHA-256 hashes
            $t->timestamp('two_factor_confirmed_at')->nullable();
            // PDPA consent record (versions accepted at registration or later).
            $t->string('terms_version', 20)->nullable();
            $t->string('privacy_version', 20)->nullable();
            $t->timestamp('consented_at')->nullable();
            $t->timestamp('marketing_consent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'terms_version', 'privacy_version', 'consented_at', 'marketing_consent_at']));
    }
};
