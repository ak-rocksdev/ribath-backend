<?php

namespace App\Services\Akademik;

use App\Models\ReportCard;
use App\Models\ReportCardEntry;

/**
 * The one mapping between a live recap and a finalized Rapor snapshot
 * (ADR 0001), shared by ReportCardService (writing and showing the
 * snapshot) and GradeRecapService (reading the snapshot in place of the
 * live recap for a finalized santri), so the three can never disagree.
 *
 * An entry's `breakdown` is the kitab's per-santri recap subject row at
 * finalization, frozen as-is:
 *   {subject_book: {id, title}, grading_template: {id, code, name},
 *    is_gradable: true, factors: [{code, name, score, source,
 *    is_midterm_exam, weight, normalized_weight, is_active, is_missing,
 *    missing_reason}], final_score, missing_factor_codes: [],
 *    is_complete: true, midterm_excluded, uts_enabled}
 * `uts_enabled` is the semester setting at finalization (kept so the frozen
 * factor explanations still read right if UTS is toggled later); it is
 * lifted out of the subject row when presented.
 */
class ReportCardSnapshot
{
    /** Keys of a presented subject row, in the live recap's own order. */
    private const SUBJECT_KEYS = [
        'subject_book',
        'grading_template',
        'is_gradable',
        'factors',
        'final_score',
        'missing_factor_codes',
        'is_complete',
        'midterm_excluded',
    ];

    /**
     * The breakdown to store for one complete, gradable subject row of
     * GradeRecapService::liveRecapForStudent().
     *
     * @param  array<string, mixed>  $subjectRow
     * @return array<string, mixed>
     */
    public function breakdownFromSubjectRow(array $subjectRow, bool $utsEnabled): array
    {
        $breakdown = [];
        foreach (self::SUBJECT_KEYS as $key) {
            $breakdown[$key] = $subjectRow[$key];
        }
        $breakdown['uts_enabled'] = $utsEnabled;

        return $breakdown;
    }

    /**
     * The frozen subject rows of a report card — same shape as
     * recapForStudent()'s `subjects` — ordered by kitab title like the live
     * recap. Expects `entries` to be loaded (or loads them).
     *
     * @return array<int, array<string, mixed>>
     */
    public function subjects(ReportCard $reportCard): array
    {
        return $reportCard->entries
            ->sortBy(fn (ReportCardEntry $entry) => $entry->breakdown['subject_book']['title'] ?? '')
            ->map(fn (ReportCardEntry $entry) => $this->subjectFromEntry($entry))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function subjectFromEntry(ReportCardEntry $entry): array
    {
        $subject = [];
        foreach (self::SUBJECT_KEYS as $key) {
            $subject[$key] = $entry->breakdown[$key] ?? null;
        }

        // JSON drops the ".0" of whole floats; restore the live recap's
        // float types so PHP readers (e.g. the PDF) see the same values.
        $subject['final_score'] = $this->toNullableFloat($subject['final_score']);
        $subject['factors'] = collect($subject['factors'] ?? [])
            ->map(fn (array $factor) => array_merge($factor, [
                'score' => $this->toNullableFloat($factor['score'] ?? null),
                'weight' => $this->toNullableFloat($factor['weight'] ?? null),
                'normalized_weight' => $this->toNullableFloat($factor['normalized_weight'] ?? null),
            ]))
            ->all();

        return $subject;
    }

    private function toNullableFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    /**
     * The semester's uts_enabled at finalization (from any entry), or null
     * when there is no entry to read it from.
     */
    public function utsEnabled(ReportCard $reportCard): ?bool
    {
        $firstEntry = $reportCard->entries->first();

        return $firstEntry === null ? null : (bool) ($firstEntry->breakdown['uts_enabled'] ?? false);
    }

    /**
     * A class recap row (GradeRecapService::recapForClassSubject) read from
     * a frozen entry instead of the live calculation.
     *
     * @param  array<string, mixed>  $presentedStudent
     * @return array<string, mixed>
     */
    public function classRowFromEntry(array $presentedStudent, ReportCardEntry $entry, ReportCard $reportCard): array
    {
        $subject = $this->subjectFromEntry($entry);

        return [
            'student' => $presentedStudent,
            'midterm_excluded' => (bool) $subject['midterm_excluded'],
            'factors' => $subject['factors'],
            'final_score' => $subject['final_score'],
            'missing_factor_codes' => $subject['missing_factor_codes'],
            'is_complete' => (bool) $subject['is_complete'],
            'is_finalized' => true,
            'is_snapshot' => true,
            'finalized_at' => $reportCard->finalized_at?->toJSON(),
        ];
    }
}
