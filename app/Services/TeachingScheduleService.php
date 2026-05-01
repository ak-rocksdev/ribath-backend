<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\School;
use App\Models\Teacher;
use App\Models\TeachingSchedule;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TeachingScheduleService
{
    private const DAY_LABELS = [
        'monday'    => 'Senin',
        'tuesday'   => 'Selasa',
        'wednesday' => 'Rabu',
        'thursday'  => 'Kamis',
        'friday'    => 'Jumat',
        'saturday'  => 'Sabtu',
        'sunday'    => 'Ahad',
    ];

    private const DAY_ORDER = [
        'monday' => 0, 'tuesday' => 1, 'wednesday' => 2, 'thursday' => 3,
        'friday' => 4, 'saturday' => 5, 'sunday' => 6,
    ];

    /** Per-process cache for the default logo so we don't re-read the PNG on every export. */
    private static ?string $cachedDefaultLogoDataUri = null;
    private static bool $defaultLogoLoaded = false;

    public function listSchedules(array $filters): Collection
    {
        $school = School::activeOrFail();

        $query = TeachingSchedule::where('school_id', $school->id)
            ->with(TeachingSchedule::EAGER_LOAD_RELATIONS);

        if (! empty($filters['academic_year_id'])) {
            $query->where('academic_year_id', $filters['academic_year_id']);
        }

        if (! empty($filters['semester'])) {
            $query->where('semester', (int) $filters['semester']);
        }

        if (! empty($filters['class_level_id'])) {
            $query->where('class_level_id', $filters['class_level_id']);
        }

        if (! empty($filters['day_of_week'])) {
            $query->where('day_of_week', $filters['day_of_week']);
        }

        if (! empty($filters['teacher_id'])) {
            $query->where('teacher_id', $filters['teacher_id']);
        }

        return $query->orderBy('day_of_week')
            ->orderBy('created_at')
            ->get();
    }

    public function createSchedule(array $data): TeachingSchedule
    {
        $school = School::activeOrFail();

        $data['school_id'] = $school->id;

        $this->validateNoClassSlotConflict($data);

        $this->validateNoTeacherConflict(
            teacherId: $data['teacher_id'],
            dayOfWeek: $data['day_of_week'],
            timeSlotId: $data['time_slot_id'],
            academicYearId: $data['academic_year_id'],
            semester: (int) $data['semester'],
        );

        $schedule = TeachingSchedule::create($data);

        return $schedule->load(TeachingSchedule::EAGER_LOAD_RELATIONS);
    }

    public function updateSchedule(TeachingSchedule $teachingSchedule, array $data): TeachingSchedule
    {
        $mergedData = array_merge($teachingSchedule->only([
            'school_id', 'teacher_id', 'day_of_week', 'time_slot_id',
            'academic_year_id', 'semester', 'class_level_id',
        ]), $data);

        $this->validateNoClassSlotConflict($mergedData, $teachingSchedule->id);

        $this->validateNoTeacherConflict(
            teacherId: $mergedData['teacher_id'],
            dayOfWeek: $mergedData['day_of_week'],
            timeSlotId: $mergedData['time_slot_id'],
            academicYearId: $mergedData['academic_year_id'],
            semester: (int) $mergedData['semester'],
            excludeScheduleId: $teachingSchedule->id,
        );

        $teachingSchedule->update($data);

        return $teachingSchedule->fresh()->load(TeachingSchedule::EAGER_LOAD_RELATIONS);
    }

    public function deleteSchedule(TeachingSchedule $teachingSchedule): void
    {
        $teachingSchedule->update(['is_active' => false]);
    }

    /**
     * Clone all active schedules from one semester to another.
     * Skips entries that would create conflicts in the target semester.
     *
     * @return array{created: int, skipped: int, skipped_details: array}
     */
    public function cloneSemesterSchedules(array $data): array
    {
        $school = School::activeOrFail();

        // Prevent cloning to the same period
        if ($data['source_academic_year_id'] === $data['target_academic_year_id']
            && (int) $data['source_semester'] === (int) $data['target_semester']) {
            throw ValidationException::withMessages([
                'target_semester' => 'Target semester must be different from source semester.',
            ]);
        }

        $excludeIds = $data['exclude_schedule_ids'] ?? [];

        $sourceSchedules = TeachingSchedule::where('school_id', $school->id)
            ->where('academic_year_id', $data['source_academic_year_id'])
            ->where('semester', (int) $data['source_semester'])
            ->where('is_active', true)
            ->when(count($excludeIds) > 0, fn ($q) => $q->whereNotIn('id', $excludeIds))
            ->get();

        if ($sourceSchedules->isEmpty()) {
            throw ValidationException::withMessages([
                'source_semester' => 'No active schedules found in the source semester.',
            ]);
        }

        $created = 0;
        $skipped = 0;
        $skippedDetails = [];

        DB::transaction(function () use ($sourceSchedules, $data, $school, &$created, &$skipped, &$skippedDetails) {
            foreach ($sourceSchedules as $source) {
                // Check for class-slot conflict in target
                $classConflict = TeachingSchedule::where('school_id', $school->id)
                    ->where('academic_year_id', $data['target_academic_year_id'])
                    ->where('semester', (int) $data['target_semester'])
                    ->where('day_of_week', $source->day_of_week)
                    ->where('time_slot_id', $source->time_slot_id)
                    ->where('class_level_id', $source->class_level_id)
                    ->where('is_active', true)
                    ->exists();

                // Check for teacher conflict in target
                $teacherConflict = TeachingSchedule::where('teacher_id', $source->teacher_id)
                    ->where('day_of_week', $source->day_of_week)
                    ->where('time_slot_id', $source->time_slot_id)
                    ->where('academic_year_id', $data['target_academic_year_id'])
                    ->where('semester', (int) $data['target_semester'])
                    ->where('is_active', true)
                    ->exists();

                if ($classConflict || $teacherConflict) {
                    $skipped++;
                    $skippedDetails[] = [
                        'source_id' => $source->id,
                        'reason' => $classConflict ? 'class_slot_conflict' : 'teacher_conflict',
                    ];

                    continue;
                }

                TeachingSchedule::create([
                    'school_id' => $school->id,
                    'academic_year_id' => $data['target_academic_year_id'],
                    'semester' => (int) $data['target_semester'],
                    'day_of_week' => $source->day_of_week,
                    'time_slot_id' => $source->time_slot_id,
                    'class_level_id' => $source->class_level_id,
                    'subject_book_id' => $source->subject_book_id,
                    'teacher_id' => $source->teacher_id,
                    'is_active' => true,
                ]);

                $created++;
            }
        });

        return [
            'created' => $created,
            'skipped' => $skipped,
            'skipped_details' => $skippedDetails,
            'source_total' => $sourceSchedules->count(),
        ];
    }

    /**
     * Replace one teacher with another across schedules.
     * Validates that the replacement teacher has no conflicts.
     *
     * @return array{updated: int, conflicts: array}
     */
    public function replaceTeacher(array $data): array
    {
        $school = School::activeOrFail();

        $query = TeachingSchedule::where('school_id', $school->id)
            ->where('teacher_id', $data['source_teacher_id'])
            ->where('is_active', true);

        if (! empty($data['academic_year_id'])) {
            $query->where('academic_year_id', $data['academic_year_id']);
        }

        if (! empty($data['semester'])) {
            $query->where('semester', (int) $data['semester']);
        }

        $schedules = $query->get();

        if ($schedules->isEmpty()) {
            throw ValidationException::withMessages([
                'source_teacher_id' => 'No active schedules found for this teacher.',
            ]);
        }

        $updated = 0;
        $conflicts = [];

        DB::transaction(function () use ($schedules, $data, &$updated, &$conflicts) {
            foreach ($schedules as $schedule) {
                $conflict = $this->findTeacherConflict(
                    teacherId: $data['target_teacher_id'],
                    dayOfWeek: $schedule->day_of_week,
                    timeSlotId: $schedule->time_slot_id,
                    academicYearId: $schedule->academic_year_id,
                    semester: $schedule->semester,
                );

                if ($conflict) {
                    $conflicts[] = [
                        'schedule_id' => $schedule->id,
                        'day_of_week' => $schedule->day_of_week,
                        'time_slot_id' => $schedule->time_slot_id,
                        'conflicting_class' => $conflict->classLevel->label ?? null,
                    ];

                    continue;
                }

                $schedule->update(['teacher_id' => $data['target_teacher_id']]);
                $updated++;
            }
        });

        return [
            'updated' => $updated,
            'conflicts' => $conflicts,
            'total' => $schedules->count(),
        ];
    }

    public function findTeacherConflict(
        string $teacherId,
        string $dayOfWeek,
        string $timeSlotId,
        string $academicYearId,
        int $semester,
        ?string $excludeScheduleId = null,
    ): ?TeachingSchedule {
        $query = TeachingSchedule::where('teacher_id', $teacherId)
            ->where('day_of_week', $dayOfWeek)
            ->where('time_slot_id', $timeSlotId)
            ->where('academic_year_id', $academicYearId)
            ->where('semester', $semester)
            ->where('is_active', true);

        if ($excludeScheduleId) {
            $query->where('id', '!=', $excludeScheduleId);
        }

        return $query->with('classLevel:id,label')->first();
    }

    private function validateNoClassSlotConflict(array $data, ?string $excludeScheduleId = null): void
    {
        $query = TeachingSchedule::where('school_id', $data['school_id'])
            ->where('academic_year_id', $data['academic_year_id'])
            ->where('semester', $data['semester'])
            ->where('day_of_week', $data['day_of_week'])
            ->where('time_slot_id', $data['time_slot_id'])
            ->where('class_level_id', $data['class_level_id'])
            ->where('is_active', true);

        if ($excludeScheduleId) {
            $query->where('id', '!=', $excludeScheduleId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'class_level_id' => 'This class already has a schedule at the same time slot.',
            ]);
        }
    }

    private function validateNoTeacherConflict(
        string $teacherId,
        string $dayOfWeek,
        string $timeSlotId,
        string $academicYearId,
        int $semester,
        ?string $excludeScheduleId = null,
    ): void {
        $conflict = $this->findTeacherConflict(
            $teacherId,
            $dayOfWeek,
            $timeSlotId,
            $academicYearId,
            $semester,
            $excludeScheduleId,
        );

        if ($conflict) {
            throw ValidationException::withMessages([
                'teacher_id' => "This teacher is already assigned to {$conflict->classLevel->label} at the same time slot.",
            ]);
        }
    }

    /**
     * Build an export-ready view model for a teacher's weekly schedule.
     * Used by the PDF export endpoint and any future report view.
     *
     * @return array{
     *   school: array{name: ?string, address: ?string, phone: ?string, email: ?string},
     *   teacher: array{full_name: string, code: string},
     *   academic_year: array{name: ?string},
     *   semester: int,
     *   schedules_sorted: array<int, TeachingSchedule>,
     *   schedules_by_day: array<int, array{day: string, label: string, items: array<int, TeachingSchedule>}>,
     *   time_slots: array<int, array{id: string, label: string, sort_order: int}>,
     *   totals: array{sesi: int, kitab: int, kelas: int},
     *   generated_at: Carbon
     * }
     */
    public function buildTeacherExportViewModel(
        Teacher $teacher,
        string $academicYearId,
        int $semester,
    ): array {
        $teacher->loadMissing('school');

        $schedules = TeachingSchedule::query()
            ->where('school_id', $teacher->school_id)
            ->where('teacher_id', $teacher->id)
            ->where('academic_year_id', $academicYearId)
            ->where('semester', $semester)
            ->where('is_active', true)
            ->with(TeachingSchedule::EAGER_LOAD_RELATIONS)
            ->get();

        $sortedSchedules = $schedules
            ->sort(fn ($a, $b) => $this->compareSchedules($a, $b))
            ->values();

        $schedulesByDay = $this->groupSchedulesByDay($sortedSchedules);
        $timeSlots = $this->extractOrderedTimeSlots($sortedSchedules);

        // Always resolve from a real lookup so the name is correct even when the teacher has no schedules
        $academicYearName = $sortedSchedules->first()?->academicYear?->name
            ?? AcademicYear::find($academicYearId)?->name;

        return [
            'school' => [
                'name'    => $teacher->school?->name,
                'address' => $teacher->school?->address,
                'phone'   => $teacher->school?->phone,
                'email'   => $teacher->school?->email,
            ],
            'teacher' => [
                'full_name' => $teacher->full_name,
                'code'      => $teacher->code,
            ],
            'academic_year' => [
                'name' => $academicYearName,
            ],
            'semester'         => $semester,
            'schedules_sorted' => $sortedSchedules->all(),
            'schedules_by_day' => $schedulesByDay,
            'time_slots'       => $timeSlots,
            'totals'           => [
                'sesi'  => $sortedSchedules->count(),
                'kitab' => $sortedSchedules->pluck('subject_book_id')->unique()->count(),
                'kelas' => $sortedSchedules->pluck('class_level_id')->unique()->count(),
            ],
            'logo_data_uri' => $this->resolveDefaultLogoDataUri(),
            'day_labels'    => self::DAY_LABELS,
            'generated_at'  => Carbon::now('Asia/Jakarta'),
        ];
    }

    /**
     * Build the canonical filename for an exported teacher schedule PDF.
     * Example: Jadwal-AKH-Sem1-1447-1448.pdf
     */
    public function buildTeacherExportFilename(array $viewModel): string
    {
        $code = $viewModel['teacher']['code'] ?? 'Ustadz';
        $semester = $viewModel['semester'] ?? 1;
        $year = str_replace('/', '-', $viewModel['academic_year']['name'] ?? 'TA');

        return "Jadwal-{$code}-Sem{$semester}-{$year}.pdf";
    }

    /**
     * Read the default school logo from disk and return a base64 data URI.
     * Returns null if the file is missing so the Blade can render without a logo.
     * Memoised at the PHP-process level — the file is fixed at build time and
     * never changes between requests served by the same FPM worker.
     */
    private function resolveDefaultLogoDataUri(): ?string
    {
        if (! self::$defaultLogoLoaded) {
            $path = public_path('images/default-school-logo.png');
            self::$cachedDefaultLogoDataUri = file_exists($path)
                ? 'data:image/png;base64,' . base64_encode(file_get_contents($path))
                : null;
            self::$defaultLogoLoaded = true;
        }

        return self::$cachedDefaultLogoDataUri;
    }

    private function compareSchedules(TeachingSchedule $a, TeachingSchedule $b): int
    {
        $dayDiff = (self::DAY_ORDER[$a->day_of_week] ?? PHP_INT_MAX)
            - (self::DAY_ORDER[$b->day_of_week] ?? PHP_INT_MAX);

        if ($dayDiff !== 0) {
            return $dayDiff;
        }

        return ($a->timeSlot?->sort_order ?? PHP_INT_MAX)
            <=> ($b->timeSlot?->sort_order ?? PHP_INT_MAX);
    }

    /**
     * @param  Collection<int, TeachingSchedule>  $sorted
     * @return array<int, array{day: string, label: string, items: array<int, TeachingSchedule>}>
     */
    private function groupSchedulesByDay(Collection $sorted): array
    {
        $byDay = $sorted->groupBy('day_of_week');

        $groups = [];
        foreach (array_keys(self::DAY_ORDER) as $day) {
            if (! $byDay->has($day)) {
                continue;
            }

            $groups[] = [
                'day'   => $day,
                'label' => self::DAY_LABELS[$day],
                'items' => $byDay->get($day)->all(),
            ];
        }

        return $groups;
    }

    /**
     * @param  Collection<int, TeachingSchedule>  $sorted
     * @return array<int, array{id: string, label: string, sort_order: int}>
     */
    private function extractOrderedTimeSlots(Collection $sorted): array
    {
        $seen = [];
        foreach ($sorted as $schedule) {
            $slotId = $schedule->time_slot_id;
            if (isset($seen[$slotId])) {
                continue;
            }
            $seen[$slotId] = [
                'id'         => $slotId,
                'label'      => $schedule->timeSlot?->label ?? $slotId,
                'sort_order' => $schedule->timeSlot?->sort_order ?? PHP_INT_MAX,
            ];
        }

        $slots = array_values($seen);
        usort($slots, fn ($a, $b) => $a['sort_order'] <=> $b['sort_order']);

        return $slots;
    }
}
