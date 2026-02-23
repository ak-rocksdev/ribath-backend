<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('registration_period_id')->nullable()->constrained('registration_periods')->nullOnDelete();
            $table->string('registration_number')->unique();
            $table->string('status', 20)->default('new');
            $table->string('registrant_type', 10);
            $table->string('full_name', 100);
            $table->string('birth_place', 100)->nullable();
            $table->date('birth_date');
            $table->string('gender', 1);
            $table->string('preferred_program', 20);
            $table->string('guardian_name', 100)->nullable();
            $table->string('guardian_phone', 20);
            $table->string('guardian_email', 255)->nullable();
            $table->string('info_source', 50)->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamp('contacted_at')->nullable();
            $table->foreignId('contacted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('interviewed_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('registration_number');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registrations');
    }
};
