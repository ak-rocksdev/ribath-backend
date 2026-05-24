<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_book_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('name', 100);
            $table->boolean('is_system')->default(false)->comment('System category (e.g. Saldo Awal) cannot be renamed or deleted');
            $table->boolean('is_active')->default(true)->comment('Soft-disable: hidden from dropdowns, existing entries keep label');
            $table->timestamps();

            $table->unique(['school_id', 'name']);
            $table->index(['school_id', 'is_active'], 'idx_cash_book_categories_school_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_book_categories');
    }
};
