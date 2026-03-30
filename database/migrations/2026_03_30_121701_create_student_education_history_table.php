<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_education_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('student_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('last_school_name', 200)->nullable();
            $table->string('last_education_level', 20)->nullable(); // elementary, middle_school, high_school
            $table->smallInteger('graduation_year')->nullable();
            $table->text('achievements')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_education_history');
    }
};
