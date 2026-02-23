<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('psb_registrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('psb_period_id')->nullable()->constrained('psb_periods')->nullOnDelete();
            $table->string('registration_number')->unique();
            $table->string('status', 20)->default('baru');
            $table->string('registrant_type', 10);
            $table->string('nama_lengkap', 100);
            $table->string('tempat_lahir', 100)->nullable();
            $table->date('tanggal_lahir');
            $table->string('jenis_kelamin', 1);
            $table->string('program_minat', 20);
            $table->string('nama_wali', 100)->nullable();
            $table->string('no_hp_wali', 20);
            $table->string('email_wali', 255)->nullable();
            $table->string('sumber_info', 50)->nullable();
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('psb_registrations');
    }
};
