<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_fee_exceptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('student_fee_assignment_id')
                ->constrained('student_fee_assignments')
                ->cascadeOnDelete();
            $table->string('kind', 20)->comment('full_waiver | partial_nominal');
            $table->unsignedBigInteger('discount_amount')->nullable()
                ->comment('Required + >0 if partial_nominal; null if full_waiver');
            $table->text('reason');
            $table->date('effective_from')->comment('Asia/Jakarta date');
            $table->date('effective_until')->nullable()->comment('null = until santri graduates/leaves');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('student_fee_assignment_id', 'idx_exceptions_assignment');
            $table->index(['student_fee_assignment_id', 'effective_from', 'effective_until'], 'idx_exceptions_effective');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE student_fee_exceptions ADD CONSTRAINT student_fee_exceptions_kind_check CHECK (kind IN ('full_waiver', 'partial_nominal'))");
            DB::statement("ALTER TABLE student_fee_exceptions ADD CONSTRAINT student_fee_exceptions_discount_consistency_check CHECK ((kind = 'full_waiver' AND discount_amount IS NULL) OR (kind = 'partial_nominal' AND discount_amount IS NOT NULL AND discount_amount > 0))");
            DB::statement('ALTER TABLE student_fee_exceptions ADD CONSTRAINT student_fee_exceptions_effective_order_check CHECK (effective_until IS NULL OR effective_until >= effective_from)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_fee_exceptions');
    }
};
