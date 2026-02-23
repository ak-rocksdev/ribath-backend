<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->string('year', 20);
            $table->integer('wave');
            $table->timestamp('registration_open');
            $table->timestamp('registration_close');
            $table->date('entry_date');
            $table->decimal('registration_fee', 12, 2)->default(0);
            $table->decimal('monthly_tuition_fee', 12, 2)->default(0);
            $table->integer('student_quota')->nullable();
            $table->integer('enrolled_count')->default(0);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_periods');
    }
};
