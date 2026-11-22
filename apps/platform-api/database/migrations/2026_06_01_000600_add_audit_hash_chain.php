<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $t) {
            $t->char('prev_hash', 64)->nullable();
            $t->char('hash', 64)->nullable()->index();
            $t->text('chain_data')->nullable(); // canonical snapshot at insert time (what the hash covers)
        });
        // Single-row chain head, locked for every insert so concurrent writers cannot fork the chain.
        Schema::create('audit_chain_head', function (Blueprint $t) {
            $t->unsignedTinyInteger('id')->primary();
            $t->char('last_hash', 64)->nullable();
        });
        DB::table('audit_chain_head')->insert(['id' => 1, 'last_hash' => null]);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_chain_head');
        Schema::table('audit_logs', fn (Blueprint $t) => $t->dropColumn(['prev_hash', 'hash', 'chain_data']));
    }
};
