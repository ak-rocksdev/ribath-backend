<?php

namespace App\Services\Akademik;

use App\Exceptions\OutsideTeachingScopeException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use LogicException;

/**
 * What one user may work on in one Semester Akademik, as resolved by
 * TeachingScopeResolver: either everything (the user holds the "semua"
 * permission) or only his Cakupan Mengajar (the "milik sendiri"
 * permission alone, ADR 0004) — a set of Kelas × Kitab pairs plus his
 * santri bimbingan as Pembimbing Tahfizh. For a Kitab Tahfizh (any kitab
 * with the Tahfizh template) the grade and Setoran scope is counted per
 * santri: its roster is the santri bimbingan, and he records their new
 * Setoran as the Ustadz penyimak.
 */
final class TeachingScope
{
    /**
     * @param  array<string, true>|null  $classSubjectPairKeys  "<class_level_id>|<subject_book_id>" => true; null when unrestricted
     * @param  array<string, true>|null  $mentoredStudentIds  student id => true; null when unrestricted
     * @param  array<string, true>|null  $tahfizhSubjectBookIds  subject book id => true for every Kitab Tahfizh whose roster is the santri bimbingan; null when unrestricted
     * @param  string|null  $ownTeacherId  the Ustadz linked to the user; null when unrestricted or none is linked
     */
    private function __construct(
        private readonly ?array $classSubjectPairKeys,
        private readonly ?array $mentoredStudentIds,
        private readonly ?array $tahfizhSubjectBookIds,
        private readonly ?string $ownTeacherId,
    ) {}

    public static function unrestricted(): self
    {
        return new self(null, null, null, null);
    }

    /**
     * @param  string|null  $ownTeacherId  the Ustadz linked to the user (null: no Ustadz, so the scope is empty)
     * @param  iterable<array{class_level_id: string, subject_book_id: string}>  $classSubjectPairs
     * @param  iterable<string>  $mentoredStudentIds  the santri bimbingan
     * @param  iterable<string>  $tahfizhSubjectBookIds  the Kitab Tahfizh whose roster the santri bimbingan are (empty: no roster is narrowed)
     */
    public static function limitedTo(?string $ownTeacherId, iterable $classSubjectPairs, iterable $mentoredStudentIds, iterable $tahfizhSubjectBookIds): self
    {
        $classSubjectPairKeys = [];

        foreach ($classSubjectPairs as $classSubjectPair) {
            $classSubjectPairKeys[self::pairKey($classSubjectPair['class_level_id'], $classSubjectPair['subject_book_id'])] = true;
        }

        $mentoredStudentIdKeys = self::keysOf($mentoredStudentIds);

        return new self($classSubjectPairKeys, $mentoredStudentIdKeys, self::keysOf($tahfizhSubjectBookIds), $ownTeacherId);
    }

    public function includesClassSubjectPair(string $classLevelId, string $subjectBookId): bool
    {
        return $this->classSubjectPairKeys === null
            || isset($this->classSubjectPairKeys[self::pairKey($classLevelId, $subjectBookId)]);
    }

    /**
     * @throws OutsideTeachingScopeException (403) the pair is outside the Cakupan Mengajar
     */
    public function assertIncludesClassSubjectPair(string $classLevelId, string $subjectBookId): void
    {
        if (! $this->includesClassSubjectPair($classLevelId, $subjectBookId)) {
            throw OutsideTeachingScopeException::forClassSubjectPair();
        }
    }

    /**
     * Whether ANY of the Kelas forms a pair inside the scope — how a whole
     * Jadwal Mengajar is judged where it is merely LISTED or named, since a
     * jadwal gabungan carries one pair per Kelas and is recorded as a single
     * Pertemuan (ADR 0006). For a single-class schedule this is
     * includesClassSubjectPair(). An unrestricted scope always says yes,
     * even for an empty list ("tidak dibatasi berarti semuanya").
     *
     * Reading the roster of a schedule, or writing its Absensi, asks
     * includesEveryClassSubjectPair() instead: one Pertemuan covers every
     * Kelas at once, so a partial Cakupan Mengajar is not enough.
     *
     * @param  iterable<string>  $classLevelIds
     */
    public function includesAnyClassSubjectPair(iterable $classLevelIds, string $subjectBookId): bool
    {
        if ($this->classSubjectPairKeys === null) {
            return true;
        }

        foreach ($classLevelIds as $classLevelId) {
            if ($this->includesClassSubjectPair($classLevelId, $subjectBookId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  iterable<string>  $classLevelIds
     *
     * @throws OutsideTeachingScopeException (403) no Kelas of the schedule forms a pair inside the Cakupan Mengajar
     */
    public function assertIncludesAnyClassSubjectPair(iterable $classLevelIds, string $subjectBookId): void
    {
        if (! $this->includesAnyClassSubjectPair($classLevelIds, $subjectBookId)) {
            throw OutsideTeachingScopeException::forClassSubjectPair();
        }
    }

    /**
     * Whether EVERY Kelas forms a pair inside the scope. One Pertemuan of a
     * jadwal gabungan is read and written as a whole — its roster holds the
     * santri of all its Kelas, and each Absensi row feeds the Nilai Absensi
     * of its own Kelas — so reading that roster or writing that Absensi
     * needs the whole set, never just one Kelas of it (ADR 0006). For a
     * single-class schedule this is includesClassSubjectPair(); an
     * unrestricted scope always says yes.
     *
     * @param  iterable<string>  $classLevelIds
     */
    public function includesEveryClassSubjectPair(iterable $classLevelIds, string $subjectBookId): bool
    {
        if ($this->classSubjectPairKeys === null) {
            return true;
        }

        foreach ($classLevelIds as $classLevelId) {
            if (! $this->includesClassSubjectPair($classLevelId, $subjectBookId)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  iterable<string>  $classLevelIds
     *
     * @throws OutsideTeachingScopeException (403) a Kelas of the schedule lies outside the Cakupan Mengajar
     */
    public function assertIncludesEveryClassSubjectPair(iterable $classLevelIds, string $subjectBookId): void
    {
        if (! $this->includesEveryClassSubjectPair($classLevelIds, $subjectBookId)) {
            throw OutsideTeachingScopeException::forClassSubjectPair();
        }
    }

    /** Whether the santri is one of the santri bimbingan (always true when unrestricted). */
    public function includesMentoredStudent(string $studentId): bool
    {
        return $this->mentoredStudentIds === null || isset($this->mentoredStudentIds[$studentId]);
    }

    /**
     * @throws OutsideTeachingScopeException (403) the santri is not one of the santri bimbingan
     */
    public function assertIncludesMentoredStudent(string $studentId): void
    {
        if (! $this->includesMentoredStudent($studentId)) {
            throw OutsideTeachingScopeException::forStudent();
        }
    }

    /**
     * Narrows a query of rows that belong to a santri (Target Hafalan,
     * Log Setoran) to the santri bimbingan; leaves it as is when
     * unrestricted.
     */
    public function limitQueryToMentoredStudents(Builder $query, string $studentIdColumn = 'student_id'): Builder
    {
        if ($this->mentoredStudentIds === null) {
            return $query;
        }

        return $query->whereIn($studentIdColumn, array_keys($this->mentoredStudentIds));
    }

    /**
     * The part of a Kitab's roster the user may see and grade: for a
     * Kitab Tahfizh of a limited grade scope, the santri bimbingan among
     * $students; for every other Kitab, or when unrestricted, $students
     * unchanged (the pair itself is checked by assertIncludesClassSubjectPair()).
     *
     * @template TStudent of \App\Models\Student
     *
     * @param  Collection<int, TStudent>  $students
     * @return Collection<int, TStudent>
     */
    public function rosterWithinScope(string $subjectBookId, Collection $students): Collection
    {
        if ($this->tahfizhSubjectBookIds === null || ! isset($this->tahfizhSubjectBookIds[$subjectBookId])) {
            return $students;
        }

        return $students
            ->filter(fn ($student) => $this->includesMentoredStudent($student->id))
            ->values();
    }

    /**
     * The Ustadz penyimak of a new Setoran or Murajaah: the one the request
     * names for an unrestricted user; the user's own Ustadz for a limited
     * one, whatever the request names (spec: "dipaksa ke ustadz miliknya").
     * Called only after the santri was found among the santri bimbingan,
     * which a user without a linked Ustadz never has.
     */
    public function listeningTeacherIdForNewLog(string $requestedTeacherId): string
    {
        if ($this->mentoredStudentIds === null) {
            return $requestedTeacherId;
        }

        return $this->ownTeacherId
            ?? throw new LogicException('A Cakupan Mengajar without a linked Ustadz has no santri bimbingan.');
    }

    /**
     * The Ustadz penyimak of a changed Setoran or Murajaah: the one the
     * request names (or the stored one) for an unrestricted user; always
     * the stored one for a limited user — who listened is not his to
     * change, whatever the request names.
     */
    public function listeningTeacherIdForChangedLog(string $storedTeacherId, ?string $requestedTeacherId): string
    {
        if ($this->mentoredStudentIds === null) {
            return $requestedTeacherId ?? $storedTeacherId;
        }

        return $storedTeacherId;
    }

    /**
     * @param  iterable<string>  $ids
     * @return array<string, true>
     */
    private static function keysOf(iterable $ids): array
    {
        $keys = [];

        foreach ($ids as $id) {
            $keys[$id] = true;
        }

        return $keys;
    }

    private static function pairKey(string $classLevelId, string $subjectBookId): string
    {
        return $classLevelId.'|'.$subjectBookId;
    }
}
