<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tugas (Penilaian): one row per assignment given to a Kelas × Kitab in
     * a semester akademik. The manual_periodic "tugas" grading factor's
     * score is the average of a student's student_task_scores across the
     * non-soft-deleted tasks of this class × kitab × semester — see
     * TaskFactorScoreProvider.
     */
    public function up(): void
    {
        Schema::create('class_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('class_level_id')->constrained('class_levels');
            $table->foreignUuid('subject_book_id')->constrained('subject_books');
            $table->foreignUuid('academic_year_id')->constrained('academic_years');
            $table->unsignedSmallInteger('semester')->comment('1 or 2');
            $table->string('title', 150);
            $table->date('task_date');
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(
                ['school_id', 'academic_year_id', 'semester', 'class_level_id', 'subject_book_id'],
                'idx_class_tasks_school_semester_class_book'
            );
        });

        // PG-only CHECK (SQLite tests skip — the service enforces the same range).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE class_tasks ADD CONSTRAINT class_tasks_semester_check CHECK (semester IN (1, 2))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('class_tasks');
    }
};
