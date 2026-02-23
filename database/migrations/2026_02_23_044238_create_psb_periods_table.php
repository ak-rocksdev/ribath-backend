<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('psb_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->string('year', 20);
            $table->integer('gelombang');
            $table->timestamp('pendaftaran_buka');
            $table->timestamp('pendaftaran_tutup');
            $table->date('tanggal_masuk');
            $table->decimal('biaya_pendaftaran', 12, 2)->default(0);
            $table->decimal('biaya_spp_bulanan', 12, 2)->default(0);
            $table->integer('kuota_santri')->nullable();
            $table->integer('kuota_terisi')->default(0);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('psb_periods');
    }
};
