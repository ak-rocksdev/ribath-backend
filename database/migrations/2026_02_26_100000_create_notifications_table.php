<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignUuid('school_id')->nullable()->constrained()->onDelete('set null');
            $table->string('type', 20)->default('info');
            $table->string('title', 255);
            $table->text('message');
            $table->string('priority', 10)->default('medium');
            $table->string('category', 20)->default('system');
            $table->jsonb('metadata')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('action_url', 500)->nullable();
            $table->string('action_label', 100)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_read', 'created_at']);
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
