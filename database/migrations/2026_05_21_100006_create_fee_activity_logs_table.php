<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_activity_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('subject_type', 50)->comment('Enum string (BUKAN FQCN): fee_type, fee_schedule, assignment, exception, bill, payment');
            $table->uuid('subject_id');
            $table->string('action', 20)->comment('created, updated, deleted, restored, generated, reversed');
            $table->string('actor_kind', 20)->comment('user, scheduler, psb_hook, console, system');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete()
                ->comment('Nullable: populated hanya saat actor_kind = user (real human action)');
            $table->json('changes')->nullable()->comment('Diff {before, after} untuk updated; metadata untuk generated/reversed');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['school_id', 'created_at'], 'idx_fee_logs_school_time');
            $table->index(['subject_type', 'subject_id', 'created_at'], 'idx_fee_logs_subject_time');
            $table->index(['actor_id', 'created_at'], 'idx_fee_logs_actor_time');
        });

        // DB CHECK: actor_id populated iff actor_kind = 'user' (spec Clarifications ronde 2 Q1).
        // PostgreSQL supports ALTER TABLE ADD CONSTRAINT ... CHECK; SQLite (used in tests)
        // requires CHECK clauses inline in CREATE TABLE, so the runtime-driver gate keeps
        // the production constraint while letting the in-memory test database start up.
        // The service + observer layer still enforces the same invariant in code.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE fee_activity_logs ADD CONSTRAINT fee_activity_logs_actor_consistency_check
                CHECK (
                    (actor_kind = 'user' AND actor_id IS NOT NULL)
                    OR (actor_kind <> 'user' AND actor_id IS NULL)
                )
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_activity_logs');
    }
};
