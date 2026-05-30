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
     *   2. Backfill (two strategies, name-match first then date-overlap):
     *      2a. Match registration_periods.year (string) to academic_years.name
     *          within the same school.
     *      2b. For any row still NULL: find an AY in the same school whose
     *          [start_date, end_date] window contains the period.entry_date.
     *          Handles the real-world case where AY uses Hijriyah naming
     *          ("1448/1449") and registration_period.year uses Masehi
     *          ("2025/2026") — no string match possible, but calendar dates
     *          anchor them unambiguously. If multiple AYs overlap, prefer
     *          the one whose start_date is latest (newer AY for the period).
     *   3. Make academic_year_id NOT NULL (will fail if any row was left
     *      unmatched after both strategies — surface that as a deployment-
     *      time error).
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

        // 2a. Name match (cheap, deterministic) — handles consistent naming.
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

        // 2b. Date-overlap fallback — handles Hijriyah↔Masehi naming mismatch.
        DB::statement('
            UPDATE registration_periods
            SET academic_year_id = (
                SELECT id FROM academic_years
                WHERE academic_years.school_id = registration_periods.school_id
                  AND registration_periods.entry_date BETWEEN academic_years.start_date AND academic_years.end_date
                ORDER BY academic_years.start_date DESC
                LIMIT 1
            )
            WHERE academic_year_id IS NULL
        ');

        // If any row is still unmatched after both strategies, abort.
        $unmatched = DB::table('registration_periods')
            ->whereNull('academic_year_id')
            ->count();

        if ($unmatched > 0) {
            throw new \RuntimeException(
                "{$unmatched} registration_periods could not be matched to an academic_year. ".
                'Tried (a) name match on academic_years.name = registration_periods.year, and ' .
                '(b) date-overlap match on academic_years.[start_date, end_date] containing ' .
                'registration_periods.entry_date. Seed the missing AY (covering the period entry_date) ' .
                'or rename an existing AY to match before re-running this migration.'
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
