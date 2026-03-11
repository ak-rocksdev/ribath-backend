<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subject_categories', function (Blueprint $table) {
            $table->id(); // BIGINT auto-increment
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 50)->comment('URL-friendly identifier');
            $table->string('name', 100)->comment('Display name: Nahwu, Fiqh');
            $table->string('color', 50)->default('bg-gray-100')->comment('Tailwind CSS class');
            $table->text('description')->nullable();
            $table->smallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['school_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_categories');
    }
};
