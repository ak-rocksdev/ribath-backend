<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_years', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->string('name', 20)->comment('Format: 2025/2026');
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('active_semester')->default(1)->comment('1 or 2');
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->unique(['school_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_years');
    }
};
