<?php

namespace App\Services\Akademik;

use App\Models\School;
use App\Models\TeachingSchedule;
use App\Models\TeachingScheduleTeacherHistory;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The single place that decides what a user may work on in a Semester
 * Akademik (ADR 0004, 0005; CONTEXT.md "Cakupan Mengajar"):
 *
 * - the "semua" permission of the pair (e.g. `manage-grades`) → no
 *   restriction, even when the user also holds the "milik sendiri" one;
 * - only the "milik sendiri" permission (e.g. `manage-own-grades`) → the
 *   Kelas × Kitab pairs of the Jadwal Mengajar rows of that semester —
 *   active or deactivated — held by the Ustadz linked to the user, plus
 *   the pairs the riwayat pengajar of that semester records for him (a
 *   schedule since moved to another Ustadz, Kelas or Kitab); none when no
 *   Ustadz is linked;
 * - neither → 403.
 *
 * Every Penilaian service asks this resolver instead of checking roles or
 * teacher ids itself.
 */
class TeachingScopeResolver
{
    /** Each "semua" permission with its "milik sendiri" counterpart (ADR 0004). */
    private const OWN_SCOPE_PERMISSION_BY_ALL_DATA_PERMISSION = [
        'view-grades' => 'view-own-grades',
        'manage-grades' => 'manage-own-grades',
        'view-attendance' => 'view-own-attendance',
        'manage-attendance' => 'manage-own-attendance',
        'view-memorization' => 'view-own-memorization',
        'manage-memorization' => 'manage-own-memorization',
    ];

    /**
     * @param  string  $allDataPermission  the "semua" permission the action needs, e.g. `manage-grades`
     *
     * @throws AuthorizationException the user holds neither permission of the pair
     */
    public function forCurrentUser(string $allDataPermission, string $academicYearId, int $semester): TeachingScope
    {
        $ownScopePermission = self::OWN_SCOPE_PERMISSION_BY_ALL_DATA_PERMISSION[$allDataPermission]
            ?? throw new InvalidArgumentException("No \"milik sendiri\" counterpart for permission {$allDataPermission}.");

        /** @var User|null $user */
        $user = auth()->user();

        // can() goes through Gate::before, so super_admin is never restricted.
        if ($user?->can($allDataPermission)) {
            return TeachingScope::unrestricted();
        }

        if (! $user?->can($ownScopePermission)) {
            throw new AuthorizationException;
        }

        return TeachingScope::limitedToClassSubjectPairs(
            $this->classSubjectPairsTaughtBy($user, $academicYearId, $semester)
        );
    }

    /**
     * The (class_level_id, subject_book_id) pairs the Ustadz linked to the
     * user teaches or taught in the semester, in the active school: those
     * of his Jadwal Mengajar rows, active and deactivated, and those the
     * riwayat pengajar records with him as the previous Ustadz (ADR 0005).
     * A pair may appear twice; TeachingScope keeps it once.
     *
     * @return Collection<int, array{class_level_id: string, subject_book_id: string}>
     */
    private function classSubjectPairsTaughtBy(User $user, string $academicYearId, int $semester): Collection
    {
        $linkedTeacherId = $user->teacher?->id;

        if ($linkedTeacherId === null) {
            return collect();
        }

        $schoolId = School::activeOrFail()->id;

        $currentSchedulePairs = TeachingSchedule::query()
            ->where('school_id', $schoolId)
            ->where('academic_year_id', $academicYearId)
            ->where('semester', $semester)
            ->where('teacher_id', $linkedTeacherId)
            ->select(['class_level_id', 'subject_book_id'])
            ->distinct()
            ->get()
            ->map(fn (TeachingSchedule $schedule) => $schedule->only(['class_level_id', 'subject_book_id']));

        $formerSchedulePairs = TeachingScheduleTeacherHistory::query()
            ->where('school_id', $schoolId)
            ->where('academic_year_id', $academicYearId)
            ->where('semester', $semester)
            ->where('previous_teacher_id', $linkedTeacherId)
            ->select(['previous_class_level_id', 'previous_subject_book_id'])
            ->distinct()
            ->get()
            ->map(fn (TeachingScheduleTeacherHistory $historyEntry) => [
                'class_level_id' => $historyEntry->previous_class_level_id,
                'subject_book_id' => $historyEntry->previous_subject_book_id,
            ]);

        return $currentSchedulePairs->concat($formerSchedulePairs);
    }
}
