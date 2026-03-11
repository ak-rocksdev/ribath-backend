<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subject_books', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_category_id')->constrained('subject_categories');
            $table->string('title', 100)->comment('Book name: Jurumiyyah, Safinatun Najah');
            $table->json('class_levels')->comment('Array of class_level slugs: ["tamhidi","ibtida_1"]');
            $table->json('semesters')->comment('Array of semester numbers: [1,2]');
            $table->unsignedSmallInteger('sessions_per_week')->default(1)->comment('Teaching sessions per week, 1-7');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_books');
    }
};
