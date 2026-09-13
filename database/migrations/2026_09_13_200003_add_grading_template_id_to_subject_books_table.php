<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links each subject_book to the grading_template that determines which
     * grading_factors apply to it and how their weights combine into a
     * final score. Nullable: a kitab without one cannot be graded (422 at
     * grade time) until an admin assigns one — see the follow-up data
     * migration (2026_09_13_200004) for backfilling already-existing rows.
     */
    public function up(): void
    {
        Schema::table('subject_books', function (Blueprint $table) {
            $table->foreignUuid('grading_template_id')
                ->nullable()
                ->after('subject_category_id')
                ->constrained('grading_templates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subject_books', function (Blueprint $table) {
            $table->dropConstrainedForeignId('grading_template_id');
        });
    }
};
