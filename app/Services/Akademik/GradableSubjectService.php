<?php

namespace App\Services\Akademik;

use App\Models\ClassLevel;
use App\Models\ClassSession;
use App\Models\ClassTask;
use App\Models\MemorizationTarget;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Models\SubjectBook;
use App\Models\TeachingSchedule;
use App\Models\TeachingScheduleTeacherHistory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The Kelas × Kitab pairs that can be graded in a semester akademik: the
 * unique (class_level, subject_book) pairs taught in the active school for
 * that (academic_year_id, semester) — by its teaching_schedules rows, or
 * earlier by a schedule that has since moved to another Ustadz, Kelas or
 * Kitab (the riwayat pengajar, ADR 0005) — that are either
 *
 * - scheduled by at least one active row, or
 * - "stopped": no active row holds the pair — its rows are all
 *   deactivated (the "Hapus" action) or only the riwayat pengajar names
 *   it — but the pair already has Penilaian data recorded in that
 *   semester (a filled grade, a Tugas or a held Pertemuan) — so grades
 *   already entered can still be completed, while a schedule deleted or
 *   corrected before any data exists (e.g. created by mistake) disappears;
 *
 * PLUS — per ADR 0003 — one (class_level, Tahfizh kitab) pair for every
 * class that has at least one santri with a non-deleted Target Hafalan for
 * that semester (Tahfizh has no teaching_schedules row; the JSON
 * class_levels on subject_books is deliberately not used either).
 *
 * listForSemester() is the one definition: isGradablePair() is derived
 * from it, so the list and the grid/save guard can never disagree. listForCurrentUser() narrows the list to the
 * pairs the current user may work on (TeachingScopeResolver, Cakupan
 * Mengajar).
 */
class GradableSubjectService
{
    /**
     * The gradable pairs of the semester (see class doc), ordered by class
     * then kitab title. `is_schedule_stopped`: no active row holds the pair;
     * it is kept by deactivated rows or the riwayat pengajar.
     *
     * @return array<int, array{
     *     class_level_id: string,
     *     subject_book_id: string,
     *     class_level: array{id: string, slug: string, label: string},
     *     subject_book: array{id: string, title: string},
     *     grading_template: array{id: string, code: string, name: string}|null,
     *     is_gradable: bool,
     *     is_schedule_stopped: bool,
     *     teachers: array<int, array{id: string, full_name: string}>
     * }>
     */
    public function listForSemester(string $academicYearId, int $semester, ?string $classLevelId = null): array
    {
        $schedulesByPairKey = $this->semesterSchedulesQuery($academicYearId, $semester)
            ->when($classLevelId, fn (Builder $query) => $query->where('class_level_id', $classLevelId))
            ->with([
                'classLevel:id,slug,label,sort_order',
                'subjectBook:id,title,grading_template_id',
                'subjectBook.gradingTemplate:id,code,name',
                'teacher:id,full_name',
            ])
            ->get()
            ->groupBy(fn (TeachingSchedule $schedule) => self::pairKey($schedule->class_level_id, $schedule->subject_book_id));

        $teacherHistoryByPairKey = $this->semesterTeacherHistoryQuery($academicYearId, $semester)
            ->when($classLevelId, fn (Builder $query) => $query->where('previous_class_level_id', $classLevelId))
            ->with([
                'previousClassLevel:id,slug,label,sort_order',
                'previousSubjectBook:id,title,grading_template_id',
                'previousSubjectBook.gradingTemplate:id,code,name',
                'previousTeacher:id,full_name',
            ])
            ->get()
            ->groupBy(fn (TeachingScheduleTeacherHistory $historyEntry) => self::pairKey($historyEntry->previous_class_level_id, $historyEntry->previous_subject_book_id));

        $taughtPairs = $schedulesByPairKey->keys()
            ->merge($teacherHistoryByPairKey->keys())
            ->unique()
            ->mapWithKeys(fn (string $pairKey) => [$pairKey => $this->buildPairPayload(
                $schedulesByPairKey->get($pairKey, collect()),
                $teacherHistoryByPairKey->get($pairKey, collect()),
            )]);

        // One batch of queries for the whole semester, only when a stopped pair needs it.
        $pairKeysWithRecordedData = $taughtPairs->contains('is_schedule_stopped', true)
            ? $this->pairKeysWithRecordedPenilaianData($academicYearId, $semester, $classLevelId)
            : collect();

        $gradableTaughtPairs = $taughtPairs->filter(fn (array $pair, string $pairKey) => ! $pair['is_schedule_stopped']
            || $pairKeysWithRecordedData->has($pairKey));
        [$stoppedPairs, $activeSchedulePairs] = $gradableTaughtPairs->partition(fn (array $pair) => $pair['is_schedule_stopped']);

        // On a key clash: an active schedule wins (it carries the real
        // teachers), then the Tahfizh Target Hafalan pair (gradable because
        // of the targets, not stopped), then a stopped pair.
        $allPairs = $activeSchedulePairs
            ->union($this->tahfizhTargetPairs($academicYearId, $semester, $classLevelId))
            ->union($stoppedPairs);

        return $allPairs
            ->sortBy([
                ['class_level_sort_order', 'asc'],
                ['subject_book_title', 'asc'],
            ])
            ->map(function (array $pair) {
                unset($pair['class_level_sort_order'], $pair['subject_book_title']);

                return $pair;
            })
            ->values()
            ->all();
    }

    /**
     * The gradable pairs of the semester (listForSemester()) that the
     * current user may grade: all of them with `view-grades`, only those
     * inside his Cakupan Mengajar with `view-own-grades` alone.
     *
     * @return array<int, array<string, mixed>> same shape as listForSemester()
     *
     * @throws AuthorizationException the user holds neither permission
     */
    public function listForCurrentUser(string $academicYearId, int $semester, ?string $classLevelId = null): array
    {
        $teachingScope = $this->teachingScopeResolver->forCurrentUser('view-grades', $academicYearId, $semester);

        return array_values(array_filter(
            $this->listForSemester($academicYearId, $semester, $classLevelId),
            fn (array $pair) => $teachingScope->includesClassSubjectPair($pair['class_level_id'], $pair['subject_book_id']),
        ));
    }

    /**
     * Whether (class_level, subject_book) is listed by listForSemester() for
     * the class — scheduled, stopped with recorded data, or the Tahfizh
     * kitab of a class with Target Hafalan (see class doc). Used to validate
     * grade grid reads and writes.
     */
    public function isGradablePair(string $academicYearId, int $semester, string $classLevelId, string $subjectBookId): bool
    {
        return collect($this->listForSemester($academicYearId, $semester, $classLevelId))
            ->contains(fn (array $pair) => $pair['subject_book_id'] === $subjectBookId);
    }

    /** The active school's teaching_schedules of the semester, active and deactivated. */
    private function semesterSchedulesQuery(string $academicYearId, int $semester): Builder
    {
        return TeachingSchedule::query()
            ->where('school_id', School::activeOrFail()->id)
            ->where('academic_year_id', $academicYearId)
            ->where('semester', $semester);
    }

    /** The active school's riwayat pengajar entries of the semester (ADR 0005). */
    private function semesterTeacherHistoryQuery(string $academicYearId, int $semester): Builder
    {
        return TeachingScheduleTeacherHistory::query()
            ->where('school_id', School::activeOrFail()->id)
            ->where('academic_year_id', $academicYearId)
            ->where('semester', $semester);
    }

    /**
     * The pairs with Penilaian data recorded in the semester, as a
     * collection keyed by "<class_level_id>|<subject_book_id>", for the
     * active school, that (academic_year_id, semester) and class:
     *
     * - a student_grades row with a score or a level (a cleared cell, kept
     *   as a row with both NULL, does not count);
     * - a Tugas (class_tasks);
     * - a held Pertemuan (class_sessions) — cancelled ones do not count,
     *   since a libur massal cancels every active schedule in its range.
     *
     * Soft-deleted Tugas and Pertemuan do not count. Three queries,
     * whatever the number of pairs.
     *
     * @return Collection<string, true>
     */
    private function pairKeysWithRecordedPenilaianData(string $academicYearId, int $semester, ?string $onlyClassLevelId): Collection
    {
        $schoolId = School::activeOrFail()->id;

        $recordedDataQueries = [
            StudentGrade::query()->where(fn (Builder $query) => $query->whereNotNull('score')->orWhereNotNull('scale_level')),
            ClassTask::query(),
            ClassSession::query()->where('status', ClassSession::STATUS_HELD),
        ];

        return collect($recordedDataQueries)
            ->flatMap(fn (Builder $recordedDataQuery) => $recordedDataQuery
                ->where('school_id', $schoolId)
                ->where('academic_year_id', $academicYearId)
                ->where('semester', $semester)
                ->whereNotNull('class_level_id')
                ->when($onlyClassLevelId, fn (Builder $query) => $query->where('class_level_id', $onlyClassLevelId))
                ->distinct()
                ->get(['class_level_id', 'subject_book_id'])
                ->map(fn ($recordedRow) => self::pairKey($recordedRow->class_level_id, $recordedRow->subject_book_id)))
            ->mapWithKeys(fn (string $pairKey) => [$pairKey => true]);
    }

    private static function pairKey(string $classLevelId, string $subjectBookId): string
    {
        return $classLevelId.'|'.$subjectBookId;
    }

    /**
     * @param  Collection<int, TeachingSchedule>  $pairSchedules  every row of the pair in the semester, active and deactivated (may be empty)
     * @param  Collection<int, TeachingScheduleTeacherHistory>  $pairTeacherHistory  the riwayat pengajar entries naming the pair in the semester (may be empty)
     * @return array<string, mixed>
     */
    private function buildPairPayload(Collection $pairSchedules, Collection $pairTeacherHistory): array
    {
        $isScheduleStopped = ! $pairSchedules->contains('is_active', true);
        // A scheduled pair names its current teachers; a stopped pair the ones
        // who held it (its deactivated rows and the riwayat pengajar).
        $teachers = $isScheduleStopped
            ? $pairSchedules->pluck('teacher')->concat($pairTeacherHistory->pluck('previousTeacher'))
            : $pairSchedules->where('is_active', true)->pluck('teacher');

        // Every pair comes from at least one schedule row or riwayat pengajar entry.
        /** @var TeachingSchedule|null $firstSchedule */
        $firstSchedule = $pairSchedules->first();
        /** @var TeachingScheduleTeacherHistory|null $firstHistoryEntry */
        $firstHistoryEntry = $pairTeacherHistory->first();
        $classLevel = $firstSchedule?->classLevel ?? $firstHistoryEntry?->previousClassLevel;
        $subjectBook = $firstSchedule?->subjectBook ?? $firstHistoryEntry?->previousSubjectBook;
        $gradingTemplate = $subjectBook?->gradingTemplate;

        return [
            'class_level_id' => $firstSchedule?->class_level_id ?? $firstHistoryEntry->previous_class_level_id,
            'subject_book_id' => $firstSchedule?->subject_book_id ?? $firstHistoryEntry->previous_subject_book_id,
            'class_level' => $classLevel?->summary() ?? ['id' => null, 'slug' => null, 'label' => null],
            'subject_book' => [
                'id' => $subjectBook?->id,
                'title' => $subjectBook?->title,
            ],
            'grading_template' => $gradingTemplate ? [
                'id' => $gradingTemplate->id,
                'code' => $gradingTemplate->code,
                'name' => $gradingTemplate->name,
            ] : null,
            'is_gradable' => $gradingTemplate !== null,
            'is_schedule_stopped' => $isScheduleStopped,
            'teachers' => $teachers
                ->filter()
                ->unique('id')
                ->sortBy('full_name')
                ->map(fn ($teacher) => ['id' => $teacher->id, 'full_name' => $teacher->full_name])
                ->values()
                ->all(),
            'class_level_sort_order' => $classLevel?->sort_order ?? 0,
            'subject_book_title' => $subjectBook?->title ?? '',
        ];
    }

    /**
     * One (class_level, Tahfizh kitab) pair for every class of the active
     * school that has at least one santri with a non-deleted Target
     * Hafalan for the semester (ADR 0003) — keyed the same way as the
     * schedule-based pairs ("<class_level_id>|<subject_book_id>") so the
     * caller can union() the two collections.
     *
     * @return Collection<string, array<string, mixed>>
     */
    private function tahfizhTargetPairs(string $academicYearId, int $semester, ?string $onlyClassLevelId): Collection
    {
        $tahfizhBook = $this->tahfizhSubjectBook();

        if ($tahfizhBook === null) {
            return collect();
        }

        $classLevels = $this->classLevelsWithMemorizationTargets($academicYearId, $semester, $onlyClassLevelId);

        return $classLevels
            ->mapWithKeys(fn (ClassLevel $classLevel) => [
                self::pairKey($classLevel->id, $tahfizhBook->id) => [
                    'class_level_id' => $classLevel->id,
                    'subject_book_id' => $tahfizhBook->id,
                    'class_level' => $classLevel->summary(),
                    'subject_book' => [
                        'id' => $tahfizhBook->id,
                        'title' => $tahfizhBook->title,
                    ],
                    'grading_template' => [
                        'id' => $tahfizhBook->gradingTemplate->id,
                        'code' => $tahfizhBook->gradingTemplate->code,
                        'name' => $tahfizhBook->gradingTemplate->name,
                    ],
                    'is_gradable' => true,
                    'is_schedule_stopped' => false,
                    // No teaching_schedules row backs a target-derived pair, so
                    // there is no single "scheduled teacher" to report here —
                    // each target already carries its own teacher_id.
                    'teachers' => [],
                    'class_level_sort_order' => $classLevel->sort_order ?? 0,
                    'subject_book_title' => $tahfizhBook->title,
                ],
            ]);
    }

    public function __construct(
        private TahfizhSubjectBookResolver $tahfizhSubjectBookResolver,
        private TeachingScopeResolver $teachingScopeResolver,
    ) {}

    /**
     * The active school's subject book whose grading template code is
     * `tahfizh` (with the template eager-loaded), or null if the school
     * has no such book yet. Delegates to TahfizhSubjectBookResolver — the
     * one place this lookup lives, also used by Tahfidz\MemorizationLogService.
     */
    private function tahfizhSubjectBook(): ?SubjectBook
    {
        return $this->tahfizhSubjectBookResolver->resolve();
    }

    /**
     * Class levels of the active school that have at least one santri
     * (not soft-deleted) with a non-deleted Target Hafalan for the
     * (academic_year_id, semester) pair — optionally restricted to one
     * class_level_id.
     *
     * @return Collection<int, ClassLevel>
     */
    private function classLevelsWithMemorizationTargets(string $academicYearId, int $semester, ?string $onlyClassLevelId): Collection
    {
        $school = School::activeOrFail();

        $targetStudentIds = MemorizationTarget::query()
            ->where('school_id', $school->id)
            ->where('academic_year_id', $academicYearId)
            ->where('semester', $semester)
            ->pluck('student_id');

        if ($targetStudentIds->isEmpty()) {
            return collect();
        }

        $classLevelIdsQuery = Student::query()
            ->where('school_id', $school->id)
            ->whereIn('id', $targetStudentIds)
            ->whereNotNull('class_level_id');

        if ($onlyClassLevelId !== null) {
            $classLevelIdsQuery->where('class_level_id', $onlyClassLevelId);
        }

        $classLevelIds = $classLevelIdsQuery->distinct()->pluck('class_level_id');

        if ($classLevelIds->isEmpty()) {
            return collect();
        }

        return ClassLevel::query()
            ->where('school_id', $school->id)
            ->whereIn('id', $classLevelIds)
            ->get();
    }
}
