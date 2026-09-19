<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contract stage of ADR 0006: the Kelas of a Jadwal Mengajar live in
     * `teaching_schedule_class_levels` alone. Every reader moved there, so
     * the single `class_level_id` column goes, and with it the two indexes
     * built on it:
     *
     * - `unique_active_class_schedule_slot`, the partial unique index that
     *   held "one Kelas, one slot". It cannot follow the Kelas into the
     *   join table (the slot columns stay on the schedule), so the rule is
     *   kept by TeachingScheduleService inside a transaction instead —
     *   see the ADR's consequences.
     * - `idx_schedules_class`, replaced by
     *   `idx_schedule_class_levels_school_class` on the join table.
     *
     * Both must be dropped before the column: SQLite refuses to drop a
     * column an index still names.
     *
     * DDL only — the join table has held every schedule's Kelas since
     * 2026_09_19_200000 backfilled it, so no data is lost.
     */
    public function up(): void
    {
        // Raw, because the partial index was created raw (WHERE is_active = true).
        DB::statement('DROP INDEX IF EXISTS unique_active_class_schedule_slot');

        Schema::table('teaching_schedules', function (Blueprint $table) {
            $table->dropIndex('idx_schedules_class');
            $table->dropConstrainedForeignId('class_level_id');
        });
    }

    /**
     * Brings the column and its indexes back, empty: the Kelas of a schedule
     * are the join table's now, and a rollback has nothing truthful to put
     * here. Nullable for that reason — the original column was NOT NULL.
     */
    public function down(): void
    {
        Schema::table('teaching_schedules', function (Blueprint $table) {
            $table->foreignUuid('class_level_id')
                ->nullable()
                ->after('time_slot_id')
                ->constrained('class_levels');

            $table->index(['class_level_id'], 'idx_schedules_class');
        });

        DB::statement(
            'CREATE UNIQUE INDEX unique_active_class_schedule_slot '
            .'ON teaching_schedules '
            .'(school_id, academic_year_id, semester, day_of_week, time_slot_id, class_level_id) '
            .'WHERE is_active = true'
        );
    }
};
