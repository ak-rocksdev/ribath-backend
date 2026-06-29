<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bills', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignUuid('student_fee_assignment_id')->constrained('student_fee_assignments')->cascadeOnDelete();
            $table->date('billing_period_start')->comment('Tanggal lokal WIB');
            $table->date('billing_period_end');
            $table->string('cadence_at_generation', 20)->comment('Snapshot cadence saat generate');
            $table->unsignedBigInteger('expected_amount')->comment('Snapshot effective amount saat generate');
            $table->unsignedBigInteger('paid_amount')->default(0);
            $table->string('status', 10)->default('pending')->comment('pending|partial|paid|waived');
            $table->date('due_date');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['student_fee_assignment_id', 'billing_period_start']);
            $table->index(['school_id', 'billing_period_start'], 'idx_bills_school_period');
            $table->index(['school_id', 'status'], 'idx_bills_school_status');
            $table->index(['student_id', 'billing_period_start'], 'idx_bills_student_period');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE bills ADD CONSTRAINT bills_expected_amount_nonneg_check CHECK (expected_amount >= 0)');
            DB::statement('ALTER TABLE bills ADD CONSTRAINT bills_paid_amount_nonneg_check CHECK (paid_amount >= 0)');
            DB::statement('ALTER TABLE bills ADD CONSTRAINT bills_period_order_check CHECK (billing_period_end >= billing_period_start)');
            DB::statement("ALTER TABLE bills ADD CONSTRAINT bills_status_check CHECK (status IN ('pending', 'partial', 'paid', 'waived'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bills');
    }
};
