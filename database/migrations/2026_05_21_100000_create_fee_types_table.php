<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('code', 50)->comment('Admin-defined identifier; immutable after create');
            $table->string('label', 100);
            $table->string('default_cadence', 20)
                ->comment('monthly | quarterly | semesterly | yearly | once_at_enrollment');
            $table->foreignUuid('cash_book_category_id')
                ->constrained('cash_book_categories')
                ->restrictOnDelete()
                ->comment('Required FK — payment auto-create uses this as cash_book category (spec Clarifications Q3, no fallback)');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['school_id', 'code']);
            $table->index(['school_id', 'is_active'], 'idx_fee_types_school_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_types');
    }
};
