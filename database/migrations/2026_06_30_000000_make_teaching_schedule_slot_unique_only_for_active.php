<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replace the full unique constraint on the schedule slot with a PARTIAL
     * unique index that only applies to active rows.
     *
     * Deleting a schedule is a soft-delete (is_active = false): the row is kept
     * for history/audit. The original unique constraint ignored is_active, so a
     * soft-deleted row kept occupying its slot and blocked creating a new
     * schedule there. A partial index (WHERE is_active = true) enforces "one
     * active schedule per slot" while letting inactive rows coexist.
     *
     * DDL only — no data is read, modified, or removed.
     */
    public function up(): void
    {
        // Drops the unique CONSTRAINT on Postgres / unique INDEX on SQLite, by name.
        Schema::table('teaching_schedules', function (Blueprint $table) {
            $table->dropUnique('unique_class_schedule_slot');
        });

        // Partial unique index — syntax valid on both PostgreSQL (prod) and SQLite (tests).
        DB::statement(
            'CREATE UNIQUE INDEX unique_active_class_schedule_slot '
            .'ON teaching_schedules '
            .'(school_id, academic_year_id, semester, day_of_week, time_slot_id, class_level_id) '
            .'WHERE is_active = true'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS unique_active_class_schedule_slot');

        // Note: rollback may fail if duplicate inactive rows now share a slot,
        // since the full constraint does not account for is_active.
        Schema::table('teaching_schedules', function (Blueprint $table) {
            $table->unique(
                ['school_id', 'academic_year_id', 'semester', 'day_of_week', 'time_slot_id', 'class_level_id'],
                'unique_class_schedule_slot'
            );
        });
    }
};
