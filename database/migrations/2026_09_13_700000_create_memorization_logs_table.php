<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Log Setoran dan Murajaah (Tahfidz): one row per Setoran (`new`) or
     * Murajaah (`review`) a santri makes on a date, in Halaman, with a
     * quality score. Unlike Target Hafalan, a log does not require the
     * student to have a target for the semester (spec: a target-less log
     * is still recorded, its target achievement factor is simply NULL —
     * "Target belum diset").
     *
     * subject_book_id always resolves to the active school's Tahfizh kitab
     * (MemorizationLogService), never taken from client input, so no
     * separate index on it is needed beyond the composite below.
     *
     * No FK to memorization_targets — student_id + (academic_year_id,
     * semester) is looked up the same pair-based way academic_semesters
     * itself is (ADR 0002-style), matching Task 13's documented interface.
     */
    public function up(): void
    {
        Schema::create('memorization_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignUuid('subject_book_id')->constrained('subject_books');
            $table->foreignUuid('academic_year_id')->constrained('academic_years');
            $table->unsignedSmallInteger('semester')->comment('1 or 2');
            $table->foreignUuid('teacher_id')->constrained('teachers');
            $table->date('log_date');
            $table->string('type', 10)->comment('new (Setoran) or review (Murajaah)');
            $table->unsignedSmallInteger('juz')->nullable();
            $table->unsignedSmallInteger('start_page')->nullable();
            $table->unsignedSmallInteger('end_page')->nullable();
            $table->decimal('pages', 5, 1)->comment('Halaman; may be a half page');
            $table->string('material_note', 255)->nullable();
            $table->unsignedSmallInteger('quality_score');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(
                ['school_id', 'student_id', 'academic_year_id', 'semester', 'type'],
                'idx_memorization_logs_school_student_semester_type'
            );
        });

        // PG-only CHECKs (SQLite tests skip — the service/request enforce the same ranges).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE memorization_logs ADD CONSTRAINT memorization_logs_semester_check CHECK (semester IN (1, 2))');
            DB::statement("ALTER TABLE memorization_logs ADD CONSTRAINT memorization_logs_type_check CHECK (type IN ('new', 'review'))");
            DB::statement('ALTER TABLE memorization_logs ADD CONSTRAINT memorization_logs_quality_score_check CHECK (quality_score >= 0 AND quality_score <= 100)');
            DB::statement('ALTER TABLE memorization_logs ADD CONSTRAINT memorization_logs_pages_check CHECK (pages > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('memorization_logs');
    }
};
