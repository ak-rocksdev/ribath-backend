<?php

namespace App\Services\Akademik;

use App\Exceptions\FinalizedReportCardException;
use App\Exceptions\IncompleteReportCardException;
use App\Models\ClassLevel;
use App\Models\ReportCard;
use App\Models\ReportCardEntry;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rapor (ADR 0001): finalizing a santri's semester freezes the live recap
 * into report_cards + report_card_entries; from then on the rapor is read
 * from that snapshot, never recalculated, and per-santri source writes for
 * the semester are rejected (FinalizedReportCardGuard). Only super_admin
 * can cancel a finalization, with a recorded reason; the entries are kept
 * (ignored while draft) until the next finalization overwrites them.
 */
class ReportCardService
{
    public const MESSAGE_INCOMPLETE = 'Rapor belum bisa difinalkan: masih ada nilai kosong.';

    public const MESSAGE_NO_GRADABLE_SUBJECTS = 'Rapor belum bisa difinalkan: santri belum memiliki kitab yang dinilai semester ini.';

    public const MESSAGE_ONLY_SUPER_ADMIN_CAN_UNFINALIZE = 'Hanya super_admin yang dapat membatalkan finalisasi rapor.';

    public const MESSAGE_NOT_FINAL = 'Rapor ini belum final.';

    public const STATUS_NONE = 'none';

    public function __construct(
        private GradeRecapService $gradeRecapService,
        private StudentGradeService $studentGradeService,
        private ReportCardSnapshot $reportCardSnapshot,
    ) {}

    /**
     * Every santri of a class (listClassStudents order: active first, then
     * by name; non-active santri included and flagged) with their Rapor
     * status for the semester (none | draft | final) and completeness.
     *
     * A final rapor's counts come from its snapshot (no recalculation). All
     * other santri are recapped live together, batched per kitab
     * (GradeRecapService::liveSubjectsForClassStudents), so the query count
     * grows with the number of kitab, not of santri: ~1 (students) + 1
     * (report cards) + the per-class recap (schedules/targets ~3, semester
     * 1, template factors 1, then per kitab: grades 1 + each automatic
     * factor provider's own 1–2).
     *
     * @return array{academic_year_id: string, semester: int, class_level: array{id: string, slug: string, label: string}, rows: array<int, array<string, mixed>>, summary: array{student_count: int, final_count: int, complete_count: int}}
     *
     * @throws ValidationException MESSAGE_SEMESTER_NOT_CONFIGURED (from the live recap)
     */
    public function listForClass(string $academicYearId, int $semester, string $classLevelId): array
    {
        $school = School::activeOrFail();
        $classLevel = ClassLevel::where('school_id', $school->id)->findOrFail($classLevelId);
        $students = $this->studentGradeService->listClassStudents($classLevelId);

        $reportCardsByStudentId = ReportCard::query()
            ->where('school_id', $school->id)
            ->where('academic_year_id', $academicYearId)
            ->where('semester', $semester)
            ->whereIn('student_id', $students->pluck('id'))
            ->with('finalizer:id,name')
            ->withCount('entries')
            ->get()
            ->keyBy('student_id');

        $liveStudents = $students
            ->reject(fn (Student $student) => $reportCardsByStudentId->get($student->id)?->isFinal() ?? false)
            ->values();

        $liveRecap = $liveStudents->isEmpty()
            ? ['subjects_by_student_id' => [], 'is_complete_by_student_id' => []]
            : $this->gradeRecapService->liveSubjectsForClassStudents($liveStudents, $classLevelId, $academicYearId, $semester);

        $rows = $students
            ->map(fn (Student $student) => $this->presentClassListRow(
                $student,
                $reportCardsByStudentId->get($student->id),
                $liveRecap['subjects_by_student_id'][$student->id] ?? [],
                $liveRecap['is_complete_by_student_id'][$student->id] ?? false,
            ))
            ->values()
            ->all();

        return [
            'academic_year_id' => $academicYearId,
            'semester' => $semester,
            'class_level' => [
                'id' => $classLevel->id,
                'slug' => $classLevel->slug,
                'label' => $classLevel->label,
            ],
            'rows' => $rows,
            'summary' => [
                'student_count' => count($rows),
                'final_count' => collect($rows)->where('status', ReportCard::STATUS_FINAL)->count(),
                'complete_count' => collect($rows)->where('is_complete', true)->count(),
            ],
        ];
    }

    /**
     * Finalizes a santri's Rapor for one semester akademik: the live recap
     * must be complete (every gradable kitab complete, at least one), then
     * — in one transaction — the report card is set final (class snapshot,
     * finalized_at/by) and its entries are replaced by one frozen row per
     * gradable kitab. Re-finalizing after a cancellation overwrites the
     * entries; the last cancellation reason is kept.
     *
     * @return array<string, mixed> show()
     *
     * @throws FinalizedReportCardException already final
     * @throws IncompleteReportCardException Belum Lengkap, or no gradable kitab
     * @throws ValidationException from the live recap (no class, semester not configured)
     */
    public function finalize(Student $student, string $academicYearId, int $semester): array
    {
        $school = School::activeOrFail();

        if ($this->findReportCard($student->id, $academicYearId, $semester)?->isFinal()) {
            throw FinalizedReportCardException::forStudent();
        }

        $recap = $this->gradeRecapService->liveRecapForStudent($student, $academicYearId, $semester);
        $gradableSubjects = collect($recap['subjects'])->where('is_gradable', true)->values();
        $this->assertComplete($gradableSubjects);

        $userId = auth()->id();

        try {
            $reportCard = DB::transaction(function () use ($school, $student, $academicYearId, $semester, $recap, $gradableSubjects, $userId) {
                $reportCard = ReportCard::query()
                    ->where('school_id', $school->id)
                    ->where('student_id', $student->id)
                    ->where('academic_year_id', $academicYearId)
                    ->where('semester', $semester)
                    ->lockForUpdate()
                    ->first();

                if ($reportCard?->isFinal()) {
                    throw FinalizedReportCardException::forStudent();
                }

                $reportCard ??= new ReportCard([
                    'school_id' => $school->id,
                    'student_id' => $student->id,
                    'academic_year_id' => $academicYearId,
                    'semester' => $semester,
                    'created_by' => $userId,
                ]);

                $reportCard->fill([
                    'status' => ReportCard::STATUS_FINAL,
                    'class_level_id' => $student->class_level_id,
                    'finalized_at' => now(),
                    'finalized_by' => $userId,
                    'updated_by' => $userId,
                ]);
                $reportCard->save();

                $reportCard->entries()->delete();

                foreach ($gradableSubjects as $subject) {
                    ReportCardEntry::create([
                        'school_id' => $school->id,
                        'report_card_id' => $reportCard->id,
                        'subject_book_id' => $subject['subject_book']['id'],
                        'final_score' => $subject['final_score'],
                        'breakdown' => $this->reportCardSnapshot->breakdownFromSubjectRow($subject, $recap['uts_enabled']),
                        'created_by' => $userId,
                        'updated_by' => $userId,
                    ]);
                }

                return $reportCard;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent finalization of the same santri and semester won the insert.
            throw FinalizedReportCardException::forStudent();
        }

        return $this->show($reportCard);
    }

    /**
     * One Rapor: header plus, while final, the frozen subjects (same shape
     * as the per-santri recap's `subjects`). A draft rapor shows no
     * subjects — its old entries are ignored until re-finalized.
     *
     * @return array<string, mixed>
     */
    public function show(ReportCard $reportCard): array
    {
        $reportCard->load([
            'student:id,full_name,status,entry_date,class_level_id',
            'academicYear:id,name',
            'classLevel:id,slug,label',
            'finalizer:id,name',
            'unfinalizer:id,name',
            'entries',
        ]);

        $isFinal = $reportCard->isFinal();

        return [
            'id' => $reportCard->id,
            'student' => $reportCard->student === null ? null : $this->studentGradeService->presentClassStudent($reportCard->student),
            'academic_year' => [
                'id' => $reportCard->academicYear?->id,
                'name' => $reportCard->academicYear?->name,
            ],
            'semester' => $reportCard->semester,
            'class_level' => $reportCard->classLevel === null ? null : [
                'id' => $reportCard->classLevel->id,
                'slug' => $reportCard->classLevel->slug,
                'label' => $reportCard->classLevel->label,
            ],
            'status' => $reportCard->status,
            'is_finalized' => $isFinal,
            'finalized_at' => $reportCard->finalized_at?->toJSON(),
            'finalized_by' => $this->presentUser($reportCard->finalizer),
            'unfinalize_reason' => $reportCard->unfinalize_reason,
            'unfinalized_at' => $reportCard->unfinalized_at?->toJSON(),
            'unfinalized_by' => $this->presentUser($reportCard->unfinalizer),
            'uts_enabled' => $isFinal ? $this->reportCardSnapshot->utsEnabled($reportCard) : null,
            'subjects' => $isFinal ? $this->reportCardSnapshot->subjects($reportCard) : [],
            'created_by' => $reportCard->created_by,
            'updated_by' => $reportCard->updated_by,
            'created_at' => $reportCard->created_at?->toJSON(),
            'updated_at' => $reportCard->updated_at?->toJSON(),
        ];
    }

    /**
     * Puts a final Rapor back to draft (super_admin only), recording the
     * reason, when and by whom. Entries are kept until re-finalized.
     *
     * @return array<string, mixed> show()
     *
     * @throws AuthorizationException not super_admin
     * @throws ValidationException the rapor is not final
     */
    public function unfinalize(ReportCard $reportCard, string $reason): array
    {
        $this->assertActorCanUnfinalize();

        if (! $reportCard->isFinal()) {
            throw ValidationException::withMessages(['report_card' => self::MESSAGE_NOT_FINAL]);
        }

        $userId = auth()->id();

        $reportCard->fill([
            'status' => ReportCard::STATUS_DRAFT,
            'unfinalize_reason' => $reason,
            'unfinalized_at' => now(),
            'unfinalized_by' => $userId,
            'updated_by' => $userId,
        ]);
        $reportCard->save();

        return $this->show($reportCard);
    }

    /**
     * @throws AuthorizationException (403) unless the current user has the super_admin role
     */
    public function assertActorCanUnfinalize(): void
    {
        if (! auth()->user()?->hasRole('super_admin')) {
            throw new AuthorizationException(self::MESSAGE_ONLY_SUPER_ADMIN_CAN_UNFINALIZE);
        }
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $gradableSubjects
     *
     * @throws IncompleteReportCardException
     */
    private function assertComplete(Collection $gradableSubjects): void
    {
        if ($gradableSubjects->isEmpty()) {
            throw new IncompleteReportCardException(self::MESSAGE_NO_GRADABLE_SUBJECTS, []);
        }

        $incompleteSubjects = $gradableSubjects
            ->reject(fn (array $subject) => $subject['is_complete'])
            ->map(fn (array $subject) => [
                'subject_book' => $subject['subject_book'],
                'missing_factor_codes' => $subject['missing_factor_codes'],
                'missing_factors' => collect($subject['factors'])
                    ->filter(fn (array $factor) => $factor['is_missing'])
                    ->map(fn (array $factor) => [
                        'code' => $factor['code'],
                        'name' => $factor['name'],
                        'missing_reason' => $factor['missing_reason'],
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();

        if ($incompleteSubjects !== []) {
            throw new IncompleteReportCardException(self::MESSAGE_INCOMPLETE, $incompleteSubjects);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $liveSubjects  the santri's live recap subjects (ignored for a final rapor)
     * @return array<string, mixed>
     */
    private function presentClassListRow(Student $student, ?ReportCard $reportCard, array $liveSubjects, bool $isLiveComplete): array
    {
        if ($reportCard?->isFinal()) {
            $entryCount = (int) $reportCard->entries_count;

            return [
                'student' => $this->studentGradeService->presentClassStudent($student),
                'report_card_id' => $reportCard->id,
                'status' => ReportCard::STATUS_FINAL,
                'is_complete' => true,
                'gradable_subject_count' => $entryCount,
                'final_subject_count' => $entryCount,
                'incomplete_subject_count' => 0,
                'finalized_at' => $reportCard->finalized_at?->toJSON(),
                'finalized_by_name' => $reportCard->finalizer?->name,
            ];
        }

        $gradableSubjects = collect($liveSubjects)->where('is_gradable', true);

        return [
            'student' => $this->studentGradeService->presentClassStudent($student),
            'report_card_id' => $reportCard?->id,
            'status' => $reportCard === null ? self::STATUS_NONE : $reportCard->status,
            'is_complete' => $isLiveComplete,
            'gradable_subject_count' => $gradableSubjects->count(),
            'final_subject_count' => $gradableSubjects->where('is_complete', true)->count(),
            'incomplete_subject_count' => $gradableSubjects->where('is_complete', false)->count(),
            'finalized_at' => null,
            'finalized_by_name' => null,
        ];
    }

    private function findReportCard(string $studentId, string $academicYearId, int $semester): ?ReportCard
    {
        return ReportCard::query()
            ->where('school_id', School::activeOrFail()->id)
            ->where('student_id', $studentId)
            ->where('academic_year_id', $academicYearId)
            ->where('semester', $semester)
            ->first();
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function presentUser(?User $user): ?array
    {
        return $user === null ? null : ['id' => $user->id, 'name' => $user->name];
    }
}
