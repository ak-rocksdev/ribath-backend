<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Absensi: one santri's status at one Pertemuan (class_sessions row) —
     * present (hadir), sick (sakit), excused (izin) or absent (alpa). Rows
     * of a session that is later cancelled are kept but ignored by the
     * absensi calculator.
     */
    public function up(): void
    {
        Schema::create('student_attendances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('class_session_id')->constrained('class_sessions')->cascadeOnDelete();
            $table->foreignUuid('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('status', 10)->comment('present | sick | excused | absent');
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['class_session_id', 'student_id'], 'uniq_student_attendance_session_student');
            $table->index('student_id', 'idx_student_attendances_student');
        });

        // PG-only CHECK (SQLite tests skip — the service enforces the same values).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE student_attendances ADD CONSTRAINT student_attendances_status_check CHECK (status IN ('present', 'sick', 'excused', 'absent'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_attendances');
    }
};
