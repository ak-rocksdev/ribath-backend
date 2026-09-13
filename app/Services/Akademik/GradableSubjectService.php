<?php

namespace App\Services\Akademik;

use App\Models\School;
use App\Models\TeachingSchedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The Kelas × Kitab pairs that can be graded in a semester akademik: the
 * unique (class_level, subject_book) pairs of the active school's active
 * teaching_schedules for that (academic_year_id, semester). The JSON
 * class_levels on subject_books is deliberately not used.
 *
 * listForSemester() and isGradablePair() share one source of pairs
 * (activeSchedulesQuery), so a later rule — e.g. the Tahfizh kitab for
 * students with a memorization target (Task 13) — is added in one place.
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

        return $schedules
            ->groupBy(fn (TeachingSchedule $schedule) => $schedule->class_level_id.'|'.$schedule->subject_book_id)
            ->map(fn (Collection $pairSchedules) => $this->buildPairPayload($pairSchedules))
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
     * Whether (class_level, subject_book) is gradable in the semester —
     * i.e. scheduled by an active teaching_schedule of the active school.
     * Used to validate grade grid reads and writes.
     */
    public function isGradablePair(string $academicYearId, int $semester, string $classLevelId, string $subjectBookId): bool
    {
        return $this->activeSchedulesQuery($academicYearId, $semester)
            ->where('class_level_id', $classLevelId)
            ->where('subject_book_id', $subjectBookId)
            ->exists();
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
            'class_level' => [
                'id' => $classLevel?->id,
                'slug' => $classLevel?->slug,
                'label' => $classLevel?->label,
            ],
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
}
