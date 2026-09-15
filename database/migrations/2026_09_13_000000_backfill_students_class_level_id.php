<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Backfill students.class_level_id for rows left behind by
     * 2026_03_12_400000_add_class_level_id_to_students_table: that migration's
     * correlated subquery required students.school_id to already be set, but
     * it ran before 2026_09_12_000000_backfill_students_school_id, so every
     * student that still had a NULL school_id at the time was skipped.
     *
     * For students with a school_id, resolve class_level_id from the
     * class_levels row with a matching slug in that same school. For
     * students with a NULL school_id, resolve against the single active
     * school's class levels, but only when the slug matches exactly one
     * row there (never guess across schools).
     *
     * Unresolved rows (no matching class level, or an ambiguous NULL
     * school_id match) are left as NULL and counted in a single
     * Log::warning — no exception, this is best-effort backfill.
     *
     * Data only — no schema change. Includes soft-deleted rows so a later
     * restore works. down() is intentionally a no-op: backfilled rows can't
     * be told apart from rows that already had a value.
     */
    public function up(): void
    {
        $unresolvedCount = 0;

        $studentsWithSchool = DB::table('students')
            ->select('id', 'school_id', 'class_level')
            ->whereNull('class_level_id')
            ->whereNotNull('class_level')
            ->whereNotNull('school_id')
            ->get();

        $bySchoolAndSlug = $studentsWithSchool->groupBy(
            fn ($student) => $student->school_id.'|'.$student->class_level
        );

        foreach ($bySchoolAndSlug as $group) {
            $first = $group->first();

            $classLevelId = DB::table('class_levels')
                ->where('school_id', $first->school_id)
                ->where('slug', $first->class_level)
                ->value('id');

            if ($classLevelId === null) {
                $unresolvedCount += $group->count();

                continue;
            }

            DB::table('students')
                ->whereIn('id', $group->pluck('id'))
                ->update(['class_level_id' => $classLevelId]);
        }

        $activeSchoolIds = DB::table('schools')->where('is_active', true)->pluck('id');

        $studentsWithoutSchool = DB::table('students')
            ->select('id', 'class_level')
            ->whereNull('class_level_id')
            ->whereNull('school_id')
            ->whereNotNull('class_level')
            ->get();

        if ($activeSchoolIds->count() !== 1) {
            $unresolvedCount += $studentsWithoutSchool->count();
        } else {
            $activeSchoolId = $activeSchoolIds->first();
            $bySlug = $studentsWithoutSchool->groupBy('class_level');

            foreach ($bySlug as $slug => $group) {
                $matchingClassLevelIds = DB::table('class_levels')
                    ->where('school_id', $activeSchoolId)
                    ->where('slug', $slug)
                    ->pluck('id');

                if ($matchingClassLevelIds->count() !== 1) {
                    $unresolvedCount += $group->count();

                    continue;
                }

                DB::table('students')
                    ->whereIn('id', $group->pluck('id'))
                    ->update(['class_level_id' => $matchingClassLevelIds->first()]);
            }
        }

        if ($unresolvedCount > 0) {
            Log::warning("backfill_students_class_level_id: {$unresolvedCount} student(s) left with an unresolved class_level_id.");
        }
    }

    public function down(): void
    {
        // Intentionally empty — see up().
    }
};
