<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rapor (ReportCard): one row per santri per semester akademik (the
     * (academic_year_id, semester) pair, ADR 0002). When finalized it is a
     * snapshot (ADR 0001): its report_card_entries freeze every kitab's
     * factor scores, normalized weights and final score, and every read
     * goes to the snapshot instead of the live calculation.
     *
     * status: draft | final. class_level_id snapshots the santri's class at
     * finalization. Cancelling a finalization (super_admin only) sets the
     * status back to draft and records the last reason, when and by whom;
     * the entries stay until the next finalization overwrites them.
     */
    public function up(): void
    {
        Schema::create('report_cards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignUuid('academic_year_id')->constrained('academic_years');
            $table->unsignedSmallInteger('semester')->comment('1 or 2');
            $table->foreignUuid('class_level_id')->nullable()->constrained('class_levels')->nullOnDelete();
            $table->string('status', 10)->default('draft');
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('unfinalize_reason')->nullable();
            $table->timestamp('unfinalized_at')->nullable();
            $table->foreignId('unfinalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['student_id', 'academic_year_id', 'semester'], 'uniq_report_card_student_semester');
            $table->index(['school_id', 'academic_year_id', 'semester', 'status'], 'idx_report_cards_school_semester_status');
        });

        // PG-only CHECKs (SQLite tests skip — the service only writes these values).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE report_cards ADD CONSTRAINT report_cards_status_check CHECK (status IN ('draft', 'final'))");
            DB::statement('ALTER TABLE report_cards ADD CONSTRAINT report_cards_semester_check CHECK (semester IN (1, 2))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_cards');
    }
};
