<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Grading factors (Penilaian): the fixed catalogue of components that
     * make up a final score (UTS, UAS, Tugas, Keaktifan, Adab, Absensi,
     * Pencapaian Target, Kualitas Setoran, Murajaah, UAS Tahfizh). Each
     * factor is scoped to a school and belongs to exactly one
     * grading_template via grading_template_factors, which also carries the
     * per-semester weight.
     *
     * - input_type: how the score is produced (manual entry once per
     *   semester, manual entry periodically, a bulk end-of-semester entry,
     *   or computed automatically from attendance/memorization logs).
     * - score_scale: percent (0-100) or level_1_4 (Kurang/Cukup/Baik/Sangat
     *   Baik, each level's numeric score defined in scale_levels).
     */
    public function up(): void
    {
        Schema::create('grading_factors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name', 100);
            $table->string('input_type', 30);
            $table->string('score_scale', 15);
            $table->json('scale_levels')->nullable()->comment('Only for score_scale = level_1_4: [{level, label, description, score}]');
            $table->boolean('is_midterm_exam')->default(false);
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['school_id', 'code']);
        });

        // PG-only CHECKs (SQLite tests skip — Form Request/service layer enforces too).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE grading_factors ADD CONSTRAINT grading_factors_input_type_check CHECK (input_type IN ('manual_once', 'manual_periodic', 'end_of_semester_bulk', 'auto_from_log', 'auto_from_attendance'))");
            DB::statement("ALTER TABLE grading_factors ADD CONSTRAINT grading_factors_score_scale_check CHECK (score_scale IN ('percent', 'level_1_4'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('grading_factors');
    }
};
