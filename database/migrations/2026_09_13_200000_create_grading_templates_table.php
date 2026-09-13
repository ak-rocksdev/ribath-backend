<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Grading templates (Penilaian): one per subject family per school —
     * "teori_kitab" (regular kitab classes) and "tahfizh" (Qur'an
     * memorization). A subject_book is assigned to exactly one template
     * (see Task 1's TahfizhSubjectBookSeeder backfill) which determines
     * which grading_factors apply and how their weights combine into a
     * final score.
     */
    public function up(): void
    {
        Schema::create('grading_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['school_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grading_templates');
    }
};
