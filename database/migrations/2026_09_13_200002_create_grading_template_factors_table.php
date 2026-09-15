<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-semester weight of a grading_factor within a grading_template —
     * the "Bobot per Semester" of Penilaian. One row per
     * (grading_template_id, grading_factor_id, academic_year_id, semester);
     * academic_year_id + semester is the same pair used everywhere else in
     * the schedule/grading domain (looked up via AcademicSemester::findByPair,
     * never a target FK — ADR 0002), so academic_year_id is a real FK here
     * only because grading_template_factors itself is the row being
     * referenced, not the other way around.
     *
     * is_active lets a factor be temporarily excluded from the 100% sum
     * without losing its configured weight (re-activating restores it).
     */
    public function up(): void
    {
        Schema::create('grading_template_factors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('grading_template_id')->constrained('grading_templates')->cascadeOnDelete();
            $table->foreignUuid('grading_factor_id')->constrained('grading_factors')->cascadeOnDelete();
            $table->foreignUuid('academic_year_id')->constrained('academic_years');
            $table->unsignedSmallInteger('semester')->comment('1 or 2');
            $table->decimal('weight', 5, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['grading_template_id', 'grading_factor_id', 'academic_year_id', 'semester'],
                'unique_template_factor_academic_semester'
            );
            $table->index(['school_id', 'academic_year_id', 'semester'], 'idx_template_factors_school_year_semester');
        });

        // PG-only CHECKs (SQLite tests skip — Form Request/service layer enforces too).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE grading_template_factors ADD CONSTRAINT grading_template_factors_semester_check CHECK (semester IN (1, 2))');
            DB::statement('ALTER TABLE grading_template_factors ADD CONSTRAINT grading_template_factors_weight_range_check CHECK (weight >= 0 AND weight <= 100)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('grading_template_factors');
    }
};
