<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Manually entered grades (Penilaian): one row per student, kitab,
     * grading factor and semester akademik — only for manual_once and
     * end_of_semester_bulk factors. The (academic_year_id, semester) pair is
     * carried like every other semester-scoped table (ADR 0002).
     *
     * score NULL means "belum diinput" and is never coalesced to 0; rows are
     * never deleted, only cleared (score NULL + updated_by), so the audit
     * trail survives. class_level_id snapshots the student's class at the
     * time of writing. scale_level (1-4) is filled for level_1_4 factors.
     */
    public function up(): void
    {
        Schema::create('student_grades', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignUuid('subject_book_id')->constrained('subject_books');
            $table->foreignUuid('grading_factor_id')->constrained('grading_factors');
            $table->foreignUuid('academic_year_id')->constrained('academic_years');
            $table->unsignedSmallInteger('semester')->comment('1 or 2');
            $table->foreignUuid('class_level_id')->nullable()->constrained('class_levels')->nullOnDelete();
            $table->decimal('score', 5, 2)->nullable();
            $table->unsignedSmallInteger('scale_level')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['student_id', 'subject_book_id', 'grading_factor_id', 'academic_year_id', 'semester'],
                'uniq_student_grade_factor_semester'
            );
            $table->index(
                ['school_id', 'academic_year_id', 'semester', 'class_level_id', 'subject_book_id'],
                'idx_student_grades_school_semester_class_book'
            );
        });

        // PG-only CHECKs (SQLite tests skip — the service enforces the same ranges).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE student_grades ADD CONSTRAINT student_grades_semester_check CHECK (semester IN (1, 2))');
            DB::statement('ALTER TABLE student_grades ADD CONSTRAINT student_grades_score_range_check CHECK (score IS NULL OR (score >= 0 AND score <= 100))');
            DB::statement('ALTER TABLE student_grades ADD CONSTRAINT student_grades_scale_level_range_check CHECK (scale_level IS NULL OR (scale_level >= 1 AND scale_level <= 4))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_grades');
    }
};
