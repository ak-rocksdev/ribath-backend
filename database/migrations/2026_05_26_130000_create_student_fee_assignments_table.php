<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_fee_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignUuid('fee_type_id')->constrained('fee_types')->restrictOnDelete();
            $table->unsignedBigInteger('locked_amount')->comment('Rupiah snapshot dari fee_schedules.amount saat create. Immutable per FR-011.');
            $table->string('cadence', 20)->comment('Snapshot dari fee_types.default_cadence saat create.');
            $table->foreignUuid('source_academic_year_id')->constrained('academic_years')->restrictOnDelete();
            $table->string('source', 20)->default('auto_enrollment')->comment('auto_enrollment | manual_snapshot | manual_entry');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['student_id', 'fee_type_id']);
            $table->index('school_id', 'idx_assignments_school');
            $table->index('student_id', 'idx_assignments_student');
        });

        // PG-only CHECK constraints (SQLite tests skip — service layer guards).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE student_fee_assignments ADD CONSTRAINT student_fee_assignments_amount_nonneg_check CHECK (locked_amount >= 0)');
            DB::statement("ALTER TABLE student_fee_assignments ADD CONSTRAINT student_fee_assignments_source_check CHECK (source IN ('auto_enrollment', 'manual_snapshot', 'manual_entry'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_fee_assignments');
    }
};
