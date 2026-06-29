<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('academic_year_id')
                ->constrained('academic_years')
                ->restrictOnDelete();
            $table->foreignUuid('fee_type_id')
                ->constrained('fee_types')
                ->restrictOnDelete();
            $table->unsignedBigInteger('amount')->comment('Rupiah integer, ≥ 0 (Rp 0 boleh — free fee type)');
            $table->string('cadence_override', 20)
                ->nullable()
                ->comment('Inherit fee_types.default_cadence when null');
            $table->timestamps();

            $table->unique(['academic_year_id', 'fee_type_id']);
            $table->index('school_id', 'idx_fee_schedules_school');
        });

        // PG-only CHECK (SQLite tests skip — service-layer validates amount >= 0 too).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE fee_schedules ADD CONSTRAINT fee_schedules_amount_nonneg_check CHECK (amount >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_schedules');
    }
};
