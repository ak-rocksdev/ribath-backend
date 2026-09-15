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
 * santri bimbingan as Pembimbing Tahfizh. For the Kitab Tahfizh the
 * Cakupan Mengajar is counted per santri: its roster is the santri
 * bimbingan, and he records their Setoran as the Ustadz penyimak.
 */
final class TeachingScope
{
    /**
     * @param  array<string, true>|null  $classSubjectPairKeys  "<class_level_id>|<subject_book_id>" => true; null when unrestricted
     * @param  array<string, true>|null  $mentoredStudentIds  student id => true; null when unrestricted
     * @param  string|null  $tahfizhSubjectBookId  the Kitab Tahfizh whose roster is the santri bimbingan; null when unrestricted or the school has none
     * @param  string|null  $ownTeacherId  the Ustadz linked to the user; null when unrestricted or none is linked
     */
    private function __construct(
        private readonly ?array $classSubjectPairKeys,
        private readonly ?array $mentoredStudentIds,
        private readonly ?string $tahfizhSubjectBookId,
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
     * @param  string|null  $tahfizhSubjectBookId  the school's Kitab Tahfizh
     */
    public static function limitedTo(?string $ownTeacherId, iterable $classSubjectPairs, iterable $mentoredStudentIds, ?string $tahfizhSubjectBookId): self
    {
        $classSubjectPairKeys = [];

        foreach ($classSubjectPairs as $classSubjectPair) {
            $classSubjectPairKeys[self::pairKey($classSubjectPair['class_level_id'], $classSubjectPair['subject_book_id'])] = true;
        }

        $mentoredStudentIdKeys = [];

        foreach ($mentoredStudentIds as $mentoredStudentId) {
            $mentoredStudentIdKeys[$mentoredStudentId] = true;
        }

        return new self($classSubjectPairKeys, $mentoredStudentIdKeys, $tahfizhSubjectBookId, $ownTeacherId);
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
     * The part of a Kitab's roster the user may see and grade: for the
     * Kitab Tahfizh of a limited scope, the santri bimbingan among
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
        if ($this->mentoredStudentIds === null || $subjectBookId !== $this->tahfizhSubjectBookId) {
            return $students;
        }

        return $students
            ->filter(fn ($student) => $this->includesMentoredStudent($student->id))
            ->values();
    }

    /**
     * The Ustadz penyimak to record on a Setoran or Murajaah: the one the
     * request names for an unrestricted user; the user's own Ustadz for a
     * limited one, whatever the request names (spec: "dipaksa ke ustadz
     * miliknya"). Called only after the santri was found among the santri
     * bimbingan, which a user without a linked Ustadz never has.
     */
    public function listeningTeacherIdFor(string $requestedTeacherId): string
    {
        if ($this->mentoredStudentIds === null) {
            return $requestedTeacherId;
        }

        return $this->ownTeacherId
            ?? throw new LogicException('A Cakupan Mengajar without a linked Ustadz has no santri bimbingan.');
    }

    private static function pairKey(string $classLevelId, string $subjectBookId): string
    {
        return $classLevelId.'|'.$subjectBookId;
    }
}
