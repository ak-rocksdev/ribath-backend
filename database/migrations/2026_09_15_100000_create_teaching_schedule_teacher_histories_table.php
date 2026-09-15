<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Riwayat pengajar (ADR 0005): one row each time the Ustadz, Kelas or
     * Kitab of a Jadwal Mengajar changes — through the schedule edit or the
     * bulk "ganti ustadz" — holding the values the schedule had before the
     * change and its Semester Akademik pair. The Cakupan Mengajar of the
     * previous Ustadz keeps that Kelas × Kitab for the semester, so he can
     * go on helping until the Rapor is final. Append-only; teaching_schedules
     * itself is unchanged.
     *
     * Every reference cascades: an entry about a schedule, Ustadz, Kelas,
     * Kitab or Tahun Ajaran that no longer exists has nothing left to grant.
     */
    public function up(): void
    {
        Schema::create('teaching_schedule_teacher_histories', function (Blueprint $table) {
            // Short constraint names: the defaults would pass PostgreSQL's 63-character limit.
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools', indexName: 'teacher_histories_school_foreign')->cascadeOnDelete();
            $table->foreignUuid('teaching_schedule_id')->constrained('teaching_schedules', indexName: 'teacher_histories_schedule_foreign')->cascadeOnDelete();
            $table->foreignUuid('academic_year_id')->constrained('academic_years', indexName: 'teacher_histories_academic_year_foreign')->cascadeOnDelete();
            $table->unsignedSmallInteger('semester')->comment('1 or 2');
            $table->foreignUuid('previous_teacher_id')->constrained('teachers', indexName: 'teacher_histories_previous_teacher_foreign')->cascadeOnDelete();
            $table->foreignUuid('previous_class_level_id')->constrained('class_levels', indexName: 'teacher_histories_previous_class_level_foreign')->cascadeOnDelete();
            $table->foreignUuid('previous_subject_book_id')->constrained('subject_books', indexName: 'teacher_histories_previous_subject_book_foreign')->cascadeOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users', indexName: 'teacher_histories_changed_by_foreign')->nullOnDelete();
            $table->timestamp('changed_at')->useCurrent();

            $table->index(
                ['school_id', 'academic_year_id', 'semester', 'previous_teacher_id'],
                'idx_teacher_histories_school_semester_teacher'
            );
        });

        // PG-only CHECK (SQLite tests skip — the schedule's own semester is already 1 or 2).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE teaching_schedule_teacher_histories ADD CONSTRAINT teacher_histories_semester_check CHECK (semester IN (1, 2))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('teaching_schedule_teacher_histories');
    }
};
