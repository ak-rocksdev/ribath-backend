<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_book_activity_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('subject_type', 50)->comment('entry atau category — string literal, bukan FQCN');
            $table->uuid('subject_id');
            $table->string('action', 20)->comment('created, updated, deleted, restored');
            $table->foreignId('actor_id')->constrained('users');
            $table->json('changes')->nullable()->comment('Diff {before, after} untuk updated; null untuk created/deleted');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['school_id', 'created_at'], 'idx_cash_book_logs_school_time');
            $table->index(['subject_type', 'subject_id', 'created_at'], 'idx_cash_book_logs_subject_time');
            $table->index(['actor_id', 'created_at'], 'idx_cash_book_logs_actor_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_book_activity_logs');
    }
};
