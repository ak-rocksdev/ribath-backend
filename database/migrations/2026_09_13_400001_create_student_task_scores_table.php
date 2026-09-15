<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One student's score for one class_task (Tugas). score NULL means
     * "belum dinilai" and is never coalesced to 0; a cleared score keeps
     * the row (score NULL, updated_by set) rather than being deleted, so
     * the audit trail survives.
     */
    public function up(): void
    {
        Schema::create('student_task_scores', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('class_task_id')->constrained('class_tasks')->cascadeOnDelete();
            $table->foreignUuid('student_id')->constrained('students')->cascadeOnDelete();
            $table->decimal('score', 5, 2)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['class_task_id', 'student_id'], 'uniq_student_task_score');
        });

        // PG-only CHECK (SQLite tests skip — the service enforces the same range).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE student_task_scores ADD CONSTRAINT student_task_scores_score_range_check CHECK (score IS NULL OR (score >= 0 AND score <= 100))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_task_scores');
    }
};
