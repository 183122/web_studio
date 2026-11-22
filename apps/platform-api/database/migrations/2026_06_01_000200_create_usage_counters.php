<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_counters', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('metric', 40);
            $t->char('period', 7); // YYYY-MM
            $t->unsignedBigInteger('value')->default(0);
            $t->timestamps();
            $t->unique(['organization_id', 'metric', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_counters');
    }
};
