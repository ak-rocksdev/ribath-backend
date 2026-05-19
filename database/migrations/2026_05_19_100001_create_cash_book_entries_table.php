<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_book_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->date('transaction_date')->comment('Tanggal kejadian transaksi (Asia/Jakarta), tidak boleh future');
            $table->string('type', 8)->comment('in atau out (validated by application + form request)');
            $table->foreignUuid('category_id')->constrained('cash_book_categories')->restrictOnDelete();
            $table->text('description');
            $table->string('counterparty', 255)->nullable()->comment('Sumber atau penerima (e.g. Wali santri Ahmad, PLN)');
            $table->unsignedBigInteger('amount')->comment('Rupiah integer, > 0; validated by form request');
            $table->string('proof_file_path', 500)->nullable();
            $table->string('proof_file_mime', 100)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['school_id', 'transaction_date'], 'idx_cash_book_entries_school_date');
            $table->index(['school_id', 'type'], 'idx_cash_book_entries_school_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_book_entries');
    }
};
