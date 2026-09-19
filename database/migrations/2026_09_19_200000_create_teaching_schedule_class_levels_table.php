<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Kelas gabungan (ADR 0006): the Kelas of a Jadwal Mengajar become a set,
     * one row per schedule × Kelas, so Takmilah ba'da Isya can be taught to
     * Ibtida 2 and Tsanawiyah 1 in a single schedule.
     *
     * Expand stage of expand–contract: the table is filled from the single
     * `teaching_schedules.class_level_id`, and every write from now on fills
     * both, so the two can never disagree. The old column and its partial
     * unique index stay until the contract migration removes them; "one Kelas,
     * one slot" is guarded by TeachingScheduleService in the meantime.
     *
     * Constraint names are shortened by hand: the generated ones would pass
     * PostgreSQL's 63-character limit.
     */
    public function up(): void
    {
        Schema::create('teaching_schedule_class_levels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')
                ->constrained('schools', indexName: 'schedule_class_levels_school_foreign')
                ->cascadeOnDelete();
            $table->foreignUuid('teaching_schedule_id')
                ->constrained('teaching_schedules', indexName: 'schedule_class_levels_schedule_foreign')
                ->cascadeOnDelete();
            $table->foreignUuid('class_level_id')
                ->constrained('class_levels', indexName: 'schedule_class_levels_class_level_foreign')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['teaching_schedule_id', 'class_level_id'], 'unique_schedule_class_level');
            $table->index(['school_id', 'class_level_id'], 'idx_schedule_class_levels_school_class');
        });

        $this->backfillFromSingleClassLevelColumn();
    }

    public function down(): void
    {
        Schema::dropIfExists('teaching_schedule_class_levels');
    }

    /**
     * One row per existing schedule. Chunked and UUID-generated in PHP so the
     * same code runs on PostgreSQL and on the SQLite used by the tests.
     */
    private function backfillFromSingleClassLevelColumn(): void
    {
        $now = now();

        DB::table('teaching_schedules')
            ->orderBy('id')
            ->select(['id', 'school_id', 'class_level_id'])
            ->chunk(500, function ($schedules) use ($now) {
                $rows = [];

                foreach ($schedules as $schedule) {
                    if ($schedule->class_level_id === null) {
                        continue;
                    }

                    $rows[] = [
                        'id' => (string) Str::uuid(),
                        'school_id' => $schedule->school_id,
                        'teaching_schedule_id' => $schedule->id,
                        'class_level_id' => $schedule->class_level_id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('teaching_schedule_class_levels')->insert($rows);
                }
            });
    }
};
