<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * The Kelas one Pertemuan was held for (ADR 0006), mirroring
     * `teaching_schedule_class_levels`: a jadwal gabungan stays a single
     * Pertemuan row, and this table says which Kelas that Pertemuan
     * covered — written once, when the Pertemuan is first stored, and never
     * re-derived from the schedule afterwards. Changing the Kelas of a
     * schedule therefore leaves the Pertemuan already recorded alone.
     *
     * `class_sessions.class_level_id` stays as the Kelas utama: the snapshot
     * for display, for the per-Pertemuan Cakupan Mengajar guard and for the
     * session list filter.
     *
     * Constraint names are shortened by hand: the generated ones would pass
     * PostgreSQL's 63-character limit.
     */
    public function up(): void
    {
        Schema::create('class_session_class_levels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')
                ->constrained('schools', indexName: 'session_class_levels_school_foreign')
                ->cascadeOnDelete();
            $table->foreignUuid('class_session_id')
                ->constrained('class_sessions', indexName: 'session_class_levels_session_foreign')
                ->cascadeOnDelete();
            $table->foreignUuid('class_level_id')
                ->constrained('class_levels', indexName: 'session_class_levels_class_level_foreign')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['class_session_id', 'class_level_id'], 'unique_session_class_level');
            $table->index(['school_id', 'class_level_id'], 'idx_session_class_levels_school_class');
        });

        $this->backfillFromExistingSessions();
    }

    public function down(): void
    {
        Schema::dropIfExists('class_session_class_levels');
    }

    /**
     * The Kelas of every Pertemuan recorded so far, read exactly as the
     * services read them until now:
     *
     * - its own snapshot Kelas — every Pertemuan has one;
     * - the Kelas of its Jadwal Mengajar when the schedule still holds that
     *   snapshot Kelas, which is how a Pertemuan of a jadwal gabungan
     *   reached its second Kelas;
     * - every Kelas its Absensi rows name, so a Kelas taken off the
     *   schedule after the Pertemuan keeps it.
     *
     * Chunked, UUIDs generated in PHP and plain query builder throughout, so
     * the same code runs on PostgreSQL and on the SQLite the tests use.
     */
    private function backfillFromExistingSessions(): void
    {
        $now = now();

        DB::table('class_sessions')
            ->orderBy('id')
            ->select(['id', 'school_id', 'class_level_id', 'teaching_schedule_id'])
            ->chunk(500, function ($sessions) use ($now) {
                $scheduleClassLevelIds = DB::table('teaching_schedule_class_levels')
                    ->whereIn('teaching_schedule_id', $sessions->pluck('teaching_schedule_id')->filter()->unique())
                    ->get(['teaching_schedule_id', 'class_level_id'])
                    ->groupBy('teaching_schedule_id')
                    ->map(fn ($rows) => $rows->pluck('class_level_id')->all());

                $recordedClassLevelIds = DB::table('student_attendances')
                    ->whereIn('class_session_id', $sessions->pluck('id'))
                    ->whereNotNull('class_level_id')
                    ->distinct()
                    ->get(['class_session_id', 'class_level_id'])
                    ->groupBy('class_session_id')
                    ->map(fn ($rows) => $rows->pluck('class_level_id')->all());

                $rows = [];

                foreach ($sessions as $session) {
                    if ($session->class_level_id === null) {
                        continue;
                    }

                    $ofSchedule = $scheduleClassLevelIds->get($session->teaching_schedule_id, []);
                    $classLevelIds = array_unique(array_merge(
                        [$session->class_level_id],
                        in_array($session->class_level_id, $ofSchedule, true) ? $ofSchedule : [],
                        $recordedClassLevelIds->get($session->id, []),
                    ));

                    foreach ($classLevelIds as $classLevelId) {
                        $rows[] = [
                            'id' => (string) Str::uuid(),
                            'school_id' => $session->school_id,
                            'class_session_id' => $session->id,
                            'class_level_id' => $classLevelId,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                if ($rows !== []) {
                    DB::table('class_session_class_levels')->insert($rows);
                }
            });
    }
};
