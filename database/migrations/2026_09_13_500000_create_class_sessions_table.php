<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pertemuan: one real occurrence of a teaching schedule on one date,
     * either held (with student_attendances) or cancelled (with a reason).
     * The class, kitab and teacher are snapshotted from the schedule when
     * the session is recorded, so history stays right if the schedule
     * changes later; the semester pair is copied from the schedule.
     *
     * One live session per (teaching_schedule_id, session_date): a PARTIAL
     * unique index that ignores soft-deleted rows (ruling R5), so a
     * soft-deleted session never blocks re-recording that date. Syntax
     * valid on PostgreSQL (prod) and SQLite (tests) — same approach as
     * 2026_06_30_000000_make_teaching_schedule_slot_unique_only_for_active.
     * ClassSessionService also returns a 422 before the index would fire.
     */
    public function up(): void
    {
        Schema::create('class_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('teaching_schedule_id')->constrained('teaching_schedules');
            $table->date('session_date');
            $table->foreignUuid('academic_year_id')->constrained('academic_years');
            $table->unsignedSmallInteger('semester')->comment('1 or 2');
            $table->foreignUuid('class_level_id')->constrained('class_levels');
            $table->foreignUuid('subject_book_id')->constrained('subject_books');
            $table->foreignUuid('teacher_id')->constrained('teachers');
            $table->string('status', 10)->default('held')->comment('held | cancelled');
            $table->string('cancel_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(
                ['school_id', 'academic_year_id', 'semester', 'session_date'],
                'idx_class_sessions_school_semester_date'
            );
        });

        DB::statement(
            'CREATE UNIQUE INDEX uniq_active_class_session_schedule_date '
            .'ON class_sessions (teaching_schedule_id, session_date) '
            .'WHERE deleted_at IS NULL'
        );

        // PG-only CHECKs (SQLite tests skip — the service enforces the same values).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE class_sessions ADD CONSTRAINT class_sessions_status_check CHECK (status IN ('held', 'cancelled'))");
            DB::statement('ALTER TABLE class_sessions ADD CONSTRAINT class_sessions_semester_check CHECK (semester IN (1, 2))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('class_sessions');
    }
};
