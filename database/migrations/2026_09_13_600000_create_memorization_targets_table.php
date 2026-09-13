<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Target Hafalan (Tahfidz): one row per student per semester akademik —
     * the number of Halaman (pages) a santri must set orally in that
     * semester. Per ADR 0003, having a (non-deleted) Target Hafalan is what
     * makes a santri count as taking Tahfizh in that semester; there is no
     * class_level_id column here — the class for grading purposes is the
     * student's current class_level_id (GradableSubjectService,
     * StudentGradeService::listGradedStudents()).
     *
     * Soft-deletable with a natural key (student_id, academic_year_id,
     * semester): per R5, NO full unique constraint — a PARTIAL unique index
     * (WHERE deleted_at IS NULL) enforces "one active target per student
     * per semester" while letting soft-deleted history coexist, plus a
     * service-level 422 check (MemorizationTargetService).
     */
    public function up(): void
    {
        Schema::create('memorization_targets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignUuid('academic_year_id')->constrained('academic_years');
            $table->unsignedSmallInteger('semester')->comment('1 or 2');
            $table->decimal('target_pages', 6, 1)->comment('Halaman; a full mushaf is 604');
            $table->foreignUuid('teacher_id')->constrained('teachers');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(
                ['school_id', 'academic_year_id', 'semester'],
                'idx_memorization_targets_school_semester'
            );
        });

        // PG-only CHECKs (SQLite tests skip — the service enforces the same ranges).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE memorization_targets ADD CONSTRAINT memorization_targets_semester_check CHECK (semester IN (1, 2))');
            DB::statement('ALTER TABLE memorization_targets ADD CONSTRAINT memorization_targets_target_pages_check CHECK (target_pages > 0 AND target_pages <= 604)');
        }

        // Partial unique index — syntax valid on both PostgreSQL (prod) and SQLite (tests).
        DB::statement(
            'CREATE UNIQUE INDEX uniq_active_memorization_target_per_semester '
            .'ON memorization_targets (student_id, academic_year_id, semester) '
            .'WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('memorization_targets');
    }
};
