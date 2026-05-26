<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replace the per-period biaya + year string with a real FK to
     * academic_years. After this migration, fee_schedules is the sole
     * source of truth for biaya — landing-psb form just picks an AY and
     * inherits whatever rates are set for that AY.
     *
     * Sequence:
     *   1. Add nullable academic_year_id FK.
     *   2. Backfill: match existing registration_periods.year (string) to
     *      academic_years.name within the same school.
     *   3. Make academic_year_id NOT NULL (will fail if any row was left
     *      unmatched — surface that as a deployment-time error).
     *   4. Drop the legacy biaya columns + the now-redundant year string.
     */
    public function up(): void
    {
        Schema::table('registration_periods', function (Blueprint $table) {
            $table->foreignUuid('academic_year_id')
                ->nullable()
                ->after('school_id')
                ->constrained('academic_years')
                ->restrictOnDelete();
        });

        // Backfill: link each periode to the AY with matching name string
        // within the same school. Periode without a matching AY name stays
        // NULL — the NOT NULL conversion below will fail loudly, which is
        // what we want (forces explicit cleanup before the migration runs
        // somewhere else).
        DB::statement('
            UPDATE registration_periods
            SET academic_year_id = (
                SELECT id FROM academic_years
                WHERE academic_years.name = registration_periods.year
                  AND academic_years.school_id = registration_periods.school_id
                LIMIT 1
            )
            WHERE academic_year_id IS NULL
        ');

        // If any row is still unmatched, abort so the developer notices.
        $unmatched = DB::table('registration_periods')
            ->whereNull('academic_year_id')
            ->count();

        if ($unmatched > 0) {
            throw new \RuntimeException(
                "{$unmatched} registration_periods could not be matched to an academic_year by name. ".
                'Seed the missing AY (matching the `year` string) before re-running this migration.'
            );
        }

        Schema::table('registration_periods', function (Blueprint $table) {
            $table->uuid('academic_year_id')->nullable(false)->change();
        });

        Schema::table('registration_periods', function (Blueprint $table) {
            // year is now redundant — accessed via $period->academicYear->name.
            // registration_fee + monthly_tuition_fee are replaced by
            // fee_schedules (and, optionally, registration_period_fee_overrides
            // in a later migration for per-gelombang adjustments).
            $table->dropColumn(['year', 'registration_fee', 'monthly_tuition_fee']);
        });
    }

    public function down(): void
    {
        Schema::table('registration_periods', function (Blueprint $table) {
            $table->string('year', 20)->nullable()->after('name');
            $table->decimal('registration_fee', 12, 2)->default(0)->after('entry_date');
            $table->decimal('monthly_tuition_fee', 12, 2)->default(0)->after('registration_fee');
        });

        // Repopulate the year string from the FK so a roll-back leaves the
        // table usable by the old code path.
        DB::statement('
            UPDATE registration_periods
            SET year = (
                SELECT name FROM academic_years
                WHERE academic_years.id = registration_periods.academic_year_id
            )
        ');

        Schema::table('registration_periods', function (Blueprint $table) {
            $table->dropConstrainedForeignId('academic_year_id');
        });
    }
};
