<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-gelombang biaya override — pesantren komite kadang memutuskan
     * gelombang awal promo / gelombang reguler beda biaya pendaftaran.
     * Tabel terpisah (bukan kolom override di fee_schedules) supaya:
     *   • fee_schedules tetap "default per AY" — kontrak yang sudah lock
     *   • override punya reason field native + audit trail jelas
     *   • per-period CRUD UI bisa tanpa bingung mana override mana default
     *
     * Snapshot resolution (US2 nanti):
     *   1. Lookup override untuk (santri.registration_period_id, fee_type)
     *   2. Fall back ke fee_schedules untuk (santri.AY, fee_type)
     *
     * Override hanya berlaku untuk fee_type cadence `once_at_enrollment`
     * — guard di service layer, bukan DB constraint, karena kita perlu
     * pesan validasi yang user-friendly (Indonesia).
     */
    public function up(): void
    {
        Schema::create('registration_period_fee_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('registration_period_id')
                ->constrained('registration_periods')
                ->cascadeOnDelete();
            $table->foreignUuid('fee_type_id')
                ->constrained('fee_types')
                ->restrictOnDelete();
            $table->unsignedBigInteger('amount')->comment('Rupiah integer override, >= 0');
            $table->text('reason')->comment('Catatan keputusan komite — wajib (audit trail)');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['registration_period_id', 'fee_type_id']);
            $table->index(['school_id', 'registration_period_id'], 'idx_period_fee_overrides_school_period');
        });

        // PG-only CHECK (SQLite tests skip — service layer enforces too).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE registration_period_fee_overrides ADD CONSTRAINT period_fee_overrides_amount_nonneg_check CHECK (amount >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_period_fee_overrides');
    }
};
