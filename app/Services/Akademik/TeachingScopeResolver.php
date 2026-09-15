<?php

namespace App\Services\Akademik;

use App\Models\MemorizationTarget;
use App\Models\School;
use App\Models\Student;
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
 *   Cakupan Mengajar of the Ustadz linked to the user (empty when none is
 *   linked):
 *   - the Kelas × Kitab pairs of his Jadwal Mengajar rows of that
 *     semester — active or deactivated — plus the pairs the riwayat
 *     pengajar of that semester records for him (a schedule since moved
 *     to another Ustadz, Kelas or Kitab);
 *   - his santri bimbingan: the santri whose non-deleted Target Hafalan of
 *     that semester names him as Pembimbing Tahfizh. The Kitab Tahfizh is
 *     counted per santri (ADR 0003): its pair enters the scope only for
 *     the classes of his santri bimbingan — a Jadwal Mengajar or riwayat
 *     pengajar for the Kitab Tahfizh alone does not — and its roster is
 *     his santri bimbingan (TeachingScope::rosterWithinScope());
 * - neither → 403.
 *
 * Every Penilaian and Tahfidz service asks this resolver instead of
 * checking roles or teacher ids itself. The one view that follows who
 * holds a schedule NOW rather than the Cakupan Mengajar — the Alert
 * Pertemuan Bolong — asks currentScheduleTeacherIdsForCurrentUser().
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

    public function __construct(
        private TahfizhSubjectBookResolver $tahfizhSubjectBookResolver,
    ) {}

    /**
     * @param  string  $allDataPermission  the "semua" permission the action needs, e.g. `manage-grades`
     *
     * @throws AuthorizationException the user holds neither permission of the pair
     */
    public function forCurrentUser(string $allDataPermission, string $academicYearId, int $semester): TeachingScope
    {
        $user = $this->currentUserLimitedToOwnScope($allDataPermission);

        if ($user === null) {
            return TeachingScope::unrestricted();
        }

        $linkedTeacherId = $user->teacher?->id;

        if ($linkedTeacherId === null) {
            return TeachingScope::limitedTo(null, [], [], null);
        }

        $schoolId = School::activeOrFail()->id;
        $tahfizhSubjectBookId = $this->tahfizhSubjectBookResolver->resolve()?->id;
        $mentoredStudentIds = $this->studentIdsMentoredBy($linkedTeacherId, $schoolId, $academicYearId, $semester);

        $classSubjectPairs = $this->classSubjectPairsTaughtBy($linkedTeacherId, $schoolId, $academicYearId, $semester)
            ->reject(fn (array $pair) => $pair['subject_book_id'] === $tahfizhSubjectBookId)
            ->concat($this->tahfizhPairsOfMentoredStudents($mentoredStudentIds, $schoolId, $tahfizhSubjectBookId));

        return TeachingScope::limitedTo($linkedTeacherId, $classSubjectPairs, $mentoredStudentIds, $tahfizhSubjectBookId);
    }

    /**
     * Whose Jadwal Mengajar the user follows where only the schedules held
     * NOW count, never the riwayat pengajar — the Alert Pertemuan Bolong
     * (ADR 0005: the former Ustadz of a moved schedule is not alerted):
     *
     * - the "semua" permission → null, every schedule;
     * - only the "milik sendiri" permission → the id of the Ustadz linked
     *   to the user, or an empty list when none is linked;
     * - neither → 403.
     *
     * @param  string  $allDataPermission  the "semua" permission the view needs, e.g. `view-attendance`
     * @return array<int, string>|null
     *
     * @throws AuthorizationException the user holds neither permission of the pair
     */
    public function currentScheduleTeacherIdsForCurrentUser(string $allDataPermission): ?array
    {
        $user = $this->currentUserLimitedToOwnScope($allDataPermission);

        if ($user === null) {
            return null;
        }

        $linkedTeacherId = $user->teacher?->id;

        return $linkedTeacherId === null ? [] : [$linkedTeacherId];
    }

    /**
     * The current user when he holds only the "milik sendiri" counterpart
     * of $allDataPermission; null when he holds the "semua" permission.
     *
     * @throws AuthorizationException the user holds neither permission of the pair
     */
    private function currentUserLimitedToOwnScope(string $allDataPermission): ?User
    {
        $ownScopePermission = self::OWN_SCOPE_PERMISSION_BY_ALL_DATA_PERMISSION[$allDataPermission]
            ?? throw new InvalidArgumentException("No \"milik sendiri\" counterpart for permission {$allDataPermission}.");

        /** @var User|null $user */
        $user = auth()->user();

        // can() goes through Gate::before, so super_admin is never restricted.
        if ($user?->can($allDataPermission)) {
            return null;
        }

        if (! $user?->can($ownScopePermission)) {
            throw new AuthorizationException;
        }

        return $user;
    }

    /**
     * The (class_level_id, subject_book_id) pairs the Ustadz teaches or
     * taught in the semester, in the school: those of his Jadwal Mengajar
     * rows, active and deactivated, and those the riwayat pengajar records
     * with him as the previous Ustadz (ADR 0005). A pair may appear twice;
     * TeachingScope keeps it once.
     *
     * @return Collection<int, array{class_level_id: string, subject_book_id: string}>
     */
    private function classSubjectPairsTaughtBy(string $teacherId, string $schoolId, string $academicYearId, int $semester): Collection
    {
        $currentSchedulePairs = TeachingSchedule::query()
            ->where('school_id', $schoolId)
            ->where('academic_year_id', $academicYearId)
            ->where('semester', $semester)
            ->where('teacher_id', $teacherId)
            ->select(['class_level_id', 'subject_book_id'])
            ->distinct()
            ->get()
            ->map(fn (TeachingSchedule $schedule) => $schedule->only(['class_level_id', 'subject_book_id']));

        $formerSchedulePairs = TeachingScheduleTeacherHistory::query()
            ->where('school_id', $schoolId)
            ->where('academic_year_id', $academicYearId)
            ->where('semester', $semester)
            ->where('previous_teacher_id', $teacherId)
            ->select(['previous_class_level_id', 'previous_subject_book_id'])
            ->distinct()
            ->get()
            ->map(fn (TeachingScheduleTeacherHistory $historyEntry) => [
                'class_level_id' => $historyEntry->previous_class_level_id,
                'subject_book_id' => $historyEntry->previous_subject_book_id,
            ]);

        return $currentSchedulePairs->concat($formerSchedulePairs);
    }

    /**
     * The santri bimbingan of the Ustadz in the semester: the santri of his
     * school's non-deleted Target Hafalan rows that name him as Pembimbing
     * Tahfizh (no riwayat pengajar for bimbingan).
     *
     * @return Collection<int, string>
     */
    private function studentIdsMentoredBy(string $teacherId, string $schoolId, string $academicYearId, int $semester): Collection
    {
        return MemorizationTarget::query()
            ->where('school_id', $schoolId)
            ->where('academic_year_id', $academicYearId)
            ->where('semester', $semester)
            ->where('teacher_id', $teacherId)
            ->pluck('student_id');
    }

    /**
     * One (class, Kitab Tahfizh) pair for each current class of the santri
     * bimbingan (not soft-deleted), mirroring how GradableSubjectService
     * derives the Kitab Tahfizh pairs from Target Hafalan.
     *
     * @param  Collection<int, string>  $mentoredStudentIds
     * @return Collection<int, array{class_level_id: string, subject_book_id: string}>
     */
    private function tahfizhPairsOfMentoredStudents(Collection $mentoredStudentIds, string $schoolId, ?string $tahfizhSubjectBookId): Collection
    {
        if ($tahfizhSubjectBookId === null || $mentoredStudentIds->isEmpty()) {
            return collect();
        }

        return Student::query()
            ->where('school_id', $schoolId)
            ->whereIn('id', $mentoredStudentIds)
            ->whereNotNull('class_level_id')
            ->distinct()
            ->pluck('class_level_id')
            ->map(fn (string $classLevelId) => [
                'class_level_id' => $classLevelId,
                'subject_book_id' => $tahfizhSubjectBookId,
            ]);
    }
}
