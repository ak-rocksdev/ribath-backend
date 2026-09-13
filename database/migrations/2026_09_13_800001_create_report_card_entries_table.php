<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One frozen kitab of a finalized Rapor (ADR 0001): the final score and,
     * in `breakdown`, the kitab's whole recap row at finalization — every
     * factor's score, source, weight, normalized weight, whether it counted,
     * plus midterm_excluded and the grading template. Written only by
     * ReportCardService::finalize() (all entries replaced in one
     * transaction); never recalculated.
     *
     * final_score is NOT NULL: a Rapor can only be finalized when every
     * kitab is complete, so a frozen kitab always has a score.
     */
    public function up(): void
    {
        Schema::create('report_card_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('report_card_id')->constrained('report_cards')->cascadeOnDelete();
            $table->foreignUuid('subject_book_id')->constrained('subject_books');
            $table->decimal('final_score', 5, 2);
            $table->json('breakdown');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['report_card_id', 'subject_book_id'], 'uniq_report_card_entry_book');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE report_card_entries ADD CONSTRAINT report_card_entries_final_score_check CHECK (final_score >= 0 AND final_score <= 100)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_card_entries');
    }
};
