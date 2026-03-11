<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teaching_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('academic_year_id')->constrained('academic_years');
            $table->unsignedSmallInteger('semester')->comment('1 or 2');
            $table->string('day_of_week', 10)->comment('monday, tuesday, ..., sunday');
            $table->foreignUuid('time_slot_id')->constrained('time_slots');
            $table->foreignUuid('class_level_id')->constrained('class_levels');
            $table->foreignUuid('subject_book_id')->constrained('subject_books');
            $table->foreignUuid('teacher_id')->constrained('teachers');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Prevent double-booking a class at the same time slot
            $table->unique(
                ['school_id', 'academic_year_id', 'semester', 'day_of_week', 'time_slot_id', 'class_level_id'],
                'unique_class_schedule_slot'
            );

            // Indexes for common query patterns
            $table->index(['academic_year_id', 'semester'], 'idx_schedules_year_semester');
            $table->index(['class_level_id'], 'idx_schedules_class');
            $table->index(['teacher_id'], 'idx_schedules_teacher');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teaching_schedules');
    }
};
