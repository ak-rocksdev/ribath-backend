<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Frequency is now a property of the fee_type alone; there is no
     * use case for overriding it per academic year (the alternative is
     * to create a new fee_type with a different label like "SPP Semesteran").
     */
    public function up(): void
    {
        Schema::table('fee_schedules', function (Blueprint $table) {
            $table->dropColumn('cadence_override');
        });
    }

    public function down(): void
    {
        Schema::table('fee_schedules', function (Blueprint $table) {
            $table->string('cadence_override', 20)
                ->nullable()
                ->after('amount');
        });
    }
};
