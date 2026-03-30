<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('student_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 30); // foto, akta_kelahiran, kartu_keluarga, ijazah
            $table->string('file_path', 500);
            $table->string('original_filename', 255)->nullable();
            $table->integer('file_size')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_documents');
    }
};
