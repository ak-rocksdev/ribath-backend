<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_religious_profile', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('student_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('quran_reading_ability', 20)->nullable(); // fluent, basic, unable
            $table->smallInteger('memorized_juz')->default(0);
            $table->boolean('has_pesantren_experience')->default(false);
            $table->string('previous_pesantren_name', 200)->nullable();
            $table->text('other_skills')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_religious_profile');
    }
};
