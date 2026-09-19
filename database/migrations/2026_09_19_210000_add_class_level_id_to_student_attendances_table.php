<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Kelas a santri was recorded for at a Pertemuan (ADR 0006). A
     * Pertemuan of a jadwal gabungan covers several Kelas, so its own
     * snapshot Kelas can no longer say which Kelas a santri was absen for;
     * this column can, and the Rekap Kehadiran per Kelas is counted from
     * it — which also keeps it right for a santri who changed Kelas in the
     * middle of a semester.
     *
     * Existing rows are backfilled from the Kelas of their Pertemuan, which
     * is exactly right for every Absensi recorded so far: until now a
     * schedule held one Kelas.
     */
    public function up(): void
    {
        Schema::table('student_attendances', function (Blueprint $table) {
            $table->foreignUuid('class_level_id')
                ->nullable()
                ->after('student_id')
                ->constrained('class_levels')
                ->nullOnDelete();

            $table->index(['class_level_id', 'class_session_id'], 'idx_student_attendances_class_session');
        });

        // Chunked so a large table is not rewritten in one statement; plain
        // query builder, so it runs the same on PostgreSQL and SQLite.
        DB::table('class_sessions')
            ->select(['id', 'class_level_id'])
            ->orderBy('id')
            ->chunk(500, function ($sessions) {
                foreach ($sessions->groupBy('class_level_id') as $classLevelId => $sessionsOfClass) {
                    DB::table('student_attendances')
                        ->whereIn('class_session_id', $sessionsOfClass->pluck('id'))
                        ->whereNull('class_level_id')
                        ->update(['class_level_id' => $classLevelId]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('student_attendances', function (Blueprint $table) {
            $table->dropIndex('idx_student_attendances_class_session');
            $table->dropConstrainedForeignId('class_level_id');
        });
    }
};
