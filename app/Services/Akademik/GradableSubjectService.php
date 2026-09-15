<?php

namespace App\Services\Akademik;

use App\Models\ClassLevel;
use App\Models\MemorizationTarget;
use App\Models\School;
use App\Models\Student;
use App\Models\SubjectBook;
use App\Models\TeachingSchedule;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The Kelas × Kitab pairs that can be graded in a semester akademik: the
 * unique (class_level, subject_book) pairs of the active school's active
 * teaching_schedules for that (academic_year_id, semester), PLUS — per ADR
 * 0003 — one (class_level, Tahfizh kitab) pair for every class that has at
 * least one santri with a non-deleted Target Hafalan for that semester
 * (Tahfizh has no teaching_schedules row; the JSON class_levels on
 * subject_books is deliberately not used either).
 *
 * listForSemester() and isGradablePair() share both sources of pairs
 * (activeSchedulesQuery + tahfizhTargetPairs), so any later rule is added
 * in one place. listForCurrentUser() narrows the list to the pairs the
 * current user may work on (TeachingScopeResolver, Cakupan Mengajar).
 */
class GradableSubjectService
{
    /**
     * @return array<int, array{
     *     class_level_id: string,
     *     subject_book_id: string,
     *     class_level: array{id: string, slug: string, label: string},
     *     subject_book: array{id: string, title: string},
     *     grading_template: array{id: string, code: string, name: string}|null,
     *     is_gradable: bool,
     *     teachers: array<int, array{id: string, full_name: string}>
     * }>
     */
    public function listForSemester(string $academicYearId, int $semester, ?string $classLevelId = null): array
    {
        $schedules = $this->activeSchedulesQuery($academicYearId, $semester)
            ->when($classLevelId, fn (Builder $query) => $query->where('class_level_id', $classLevelId))
            ->with([
                'classLevel:id,slug,label,sort_order',
                'subjectBook:id,title,grading_template_id',
                'subjectBook.gradingTemplate:id,code,name',
                'teacher:id,full_name',
            ])
            ->get();

        $schedulePairs = $schedules
            ->groupBy(fn (TeachingSchedule $schedule) => $schedule->class_level_id.'|'.$schedule->subject_book_id)
            ->map(fn (Collection $pairSchedules) => $this->buildPairPayload($pairSchedules));

        // Schedule-based pairs win on a key clash (they carry real teacher
        // info); tahfizhTargetPairs() only adds classes not already covered.
        $allPairs = $schedulePairs->union(
            $this->tahfizhTargetPairs($academicYearId, $semester, $classLevelId)
        );

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
     * Whether (class_level, subject_book) is gradable in the semester —
     * scheduled by an active teaching_schedule of the active school, OR
     * (per ADR 0003) the pair is the Tahfizh kitab and the class has at
     * least one santri with a non-deleted Target Hafalan for the semester.
     * Used to validate grade grid reads and writes.
     */
    public function isGradablePair(string $academicYearId, int $semester, string $classLevelId, string $subjectBookId): bool
    {
        $isScheduled = $this->activeSchedulesQuery($academicYearId, $semester)
            ->where('class_level_id', $classLevelId)
            ->where('subject_book_id', $subjectBookId)
            ->exists();

        if ($isScheduled) {
            return true;
        }

        return $this->isTahfizhTargetPair($academicYearId, $semester, $classLevelId, $subjectBookId);
    }

    private function activeSchedulesQuery(string $academicYearId, int $semester): Builder
    {
        return TeachingSchedule::query()
            ->where('school_id', School::activeOrFail()->id)
            ->where('academic_year_id', $academicYearId)
            ->where('semester', $semester)
            ->where('is_active', true);
    }

    /**
     * @param  Collection<int, TeachingSchedule>  $pairSchedules
     * @return array<string, mixed>
     */
    private function buildPairPayload(Collection $pairSchedules): array
    {
        /** @var TeachingSchedule $firstSchedule */
        $firstSchedule = $pairSchedules->first();
        $classLevel = $firstSchedule->classLevel;
        $subjectBook = $firstSchedule->subjectBook;
        $gradingTemplate = $subjectBook?->gradingTemplate;

        return [
            'class_level_id' => $firstSchedule->class_level_id,
            'subject_book_id' => $firstSchedule->subject_book_id,
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
            'teachers' => $pairSchedules
                ->pluck('teacher')
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
                $classLevel->id.'|'.$tahfizhBook->id => [
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
                    // No teaching_schedules row backs a target-derived pair, so
                    // there is no single "scheduled teacher" to report here —
                    // each target already carries its own teacher_id.
                    'teachers' => [],
                    'class_level_sort_order' => $classLevel->sort_order ?? 0,
                    'subject_book_title' => $tahfizhBook->title,
                ],
            ]);
    }

    private function isTahfizhTargetPair(string $academicYearId, int $semester, string $classLevelId, string $subjectBookId): bool
    {
        $tahfizhBook = $this->tahfizhSubjectBook();

        if ($tahfizhBook === null || $tahfizhBook->id !== $subjectBookId) {
            return false;
        }

        return $this->classLevelsWithMemorizationTargets($academicYearId, $semester, $classLevelId)->isNotEmpty();
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
