<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_slots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30)->comment('Machine key: after_fajr, 07:00-08:00');
            $table->string('label', 50)->comment('Display name: Ba\'da Subuh, 07:00 - 08:00');
            $table->string('type', 15)->comment('prayer_based or fixed_clock');
            $table->time('start_time')->nullable()->comment('Approximate for prayer_based, exact for fixed_clock');
            $table->time('end_time')->nullable()->comment('Approximate for prayer_based, exact for fixed_clock');
            $table->smallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['school_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_slots');
    }
};
