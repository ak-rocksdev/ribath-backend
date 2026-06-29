<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignUuid('bill_id')->constrained('bills')->restrictOnDelete();
            $table->unsignedBigInteger('amount')->comment('Rupiah integer, > 0');
            $table->date('payment_date')->comment('Tanggal lokal WIB');
            $table->string('payment_method', 20)->comment('cash|transfer|other');
            $table->foreignUuid('cash_book_entry_id')
                ->nullable()
                ->constrained('cash_book_entries')
                ->nullOnDelete()
                ->comment('FK satu arah; null saat cash book reversal');
            $table->string('proof_file_path', 500)->nullable();
            $table->string('proof_file_mime', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('confirmed_by')->constrained('users');
            $table->timestamp('confirmed_at');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['school_id', 'payment_date'], 'idx_payments_school_date');
            $table->index('bill_id', 'idx_payments_bill');
            $table->index(['student_id', 'payment_date'], 'idx_payments_student_date');
            $table->index('cash_book_entry_id', 'idx_payments_cashbook');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE student_payments ADD CONSTRAINT student_payments_amount_positive_check CHECK (amount > 0)');
            DB::statement("ALTER TABLE student_payments ADD CONSTRAINT student_payments_method_check CHECK (payment_method IN ('cash', 'transfer', 'other'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_payments');
    }
};
