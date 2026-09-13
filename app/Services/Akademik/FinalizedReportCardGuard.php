<?php

namespace App\Services\Akademik;

use App\Exceptions\FinalizedReportCardException;
use App\Models\ReportCard;
use App\Models\School;

/**
 * Write protection of a finalized Rapor (ADR 0001, spec US91): once a
 * santri's rapor is final for a semester akademik, per-santri source writes
 * for that santri and semester are rejected so the snapshot and its
 * sources cannot drift apart — student_grades, task scores, changes to an
 * existing attendance row, memorization logs and targets.
 *
 * Class-level actions (recording a new Pertemuan, cancelling one, Tugas
 * CRUD) are deliberately NOT guarded: a finalized santri is protected by
 * the snapshot itself, which is never recalculated.
 *
 * A draft rapor (never finalized, or finalization cancelled) locks nothing.
 */
class FinalizedReportCardGuard
{
    /**
     * @throws FinalizedReportCardException keyed `student_id`
     */
    public function assertEditable(string $studentId, string $academicYearId, int $semester): void
    {
        if ($this->finalizedStudentIds([$studentId], $academicYearId, $semester) !== []) {
            throw FinalizedReportCardException::forStudent();
        }
    }

    /**
     * Bulk variant: one query for every santri, one error per finalized
     * santri keyed by its id.
     *
     * @param  iterable<int, string>  $studentIds
     *
     * @throws FinalizedReportCardException
     */
    public function assertEditableForStudents(iterable $studentIds, string $academicYearId, int $semester): void
    {
        $finalizedStudentIds = $this->finalizedStudentIds($studentIds, $academicYearId, $semester);

        if ($finalizedStudentIds !== []) {
            throw FinalizedReportCardException::forStudents($finalizedStudentIds);
        }
    }

    /**
     * The subset of $studentIds whose rapor is final for the semester, in
     * the order given — one query, scoped to the active school.
     *
     * @param  iterable<int, string>  $studentIds
     * @return array<int, string>
     */
    public function finalizedStudentIds(iterable $studentIds, string $academicYearId, int $semester): array
    {
        $studentIds = collect($studentIds)->filter()->unique()->values();

        if ($studentIds->isEmpty()) {
            return [];
        }

        $finalized = ReportCard::query()
            ->where('school_id', School::activeOrFail()->id)
            ->where('academic_year_id', $academicYearId)
            ->where('semester', $semester)
            ->where('status', ReportCard::STATUS_FINAL)
            ->whereIn('student_id', $studentIds)
            ->pluck('student_id')
            ->flip();

        return $studentIds->filter(fn (string $studentId) => $finalized->has($studentId))->values()->all();
    }
}
