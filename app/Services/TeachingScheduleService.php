<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\School;
use App\Models\Teacher;
use App\Models\TeachingSchedule;
use App\Models\TeachingScheduleTeacherHistory;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TeachingScheduleService
{
    private const DAY_LABELS = [
        'monday' => 'Senin',
        'tuesday' => 'Selasa',
        'wednesday' => 'Rabu',
        'thursday' => 'Kamis',
        'friday' => 'Jumat',
        'saturday' => 'Sabtu',
        'sunday' => 'Ahad',
    ];

    /**
     * The scalar attributes that say who teaches what; a change of either of
     * them, or of the Kelas set, is recorded in the riwayat pengajar (ADR
     * 0005).
     */
    private const TEACHER_AND_BOOK_ATTRIBUTES = ['teacher_id', 'subject_book_id'];

    private const DAY_ORDER = [
        'monday' => 0, 'tuesday' => 1, 'wednesday' => 2, 'thursday' => 3,
        'friday' => 4, 'saturday' => 5, 'sunday' => 6,
    ];

    private SchoolLogoResolver $schoolLogoResolver;

    public function __construct(?SchoolLogoResolver $schoolLogoResolver = null)
    {
        $this->schoolLogoResolver = $schoolLogoResolver ?? new SchoolLogoResolver;
    }

    public function listSchedules(array $filters): Collection
    {
        $school = School::activeOrFail();

        $query = TeachingSchedule::where('school_id', $school->id)
            ->where('is_active', true)
            ->with(TeachingSchedule::EAGER_LOAD_RELATIONS);

        if (! empty($filters['academic_year_id'])) {
            $query->where('academic_year_id', $filters['academic_year_id']);
        }

        if (! empty($filters['semester'])) {
            $query->where('semester', (int) $filters['semester']);
        }

        if (! empty($filters['class_level_id'])) {
            // A combined schedule is listed for every Kelas it holds (ADR 0006).
            $query->whereHas('classLevels', fn ($classLevels) => $classLevels->where('class_levels.id', $filters['class_level_id']));
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

    /**
     * The active schedules the Ustadz holds now in the semester, in the
     * active school, ordered by day and time slot (Jadwal Saya). Former
     * schedules recorded in the riwayat pengajar are not his any more.
     *
     * @return Collection<int, TeachingSchedule>
     */
    public function listActiveSchedulesHeldBy(string $teacherId, string $academicYearId, int $semester): Collection
    {
        return TeachingSchedule::query()
            ->where('school_id', School::activeOrFail()->id)
            ->where('teacher_id', $teacherId)
            ->where('academic_year_id', $academicYearId)
            ->where('semester', $semester)
            ->where('is_active', true)
            ->with(TeachingSchedule::EAGER_LOAD_RELATIONS)
            ->get()
            ->sort(fn (TeachingSchedule $first, TeachingSchedule $second) => $this->compareSchedules($first, $second))
            ->values();
    }

    public function createSchedule(array $data): TeachingSchedule
    {
        $school = School::activeOrFail();

        $classLevelIds = $this->resolveClassLevelIds((array) ($data['class_level_ids'] ?? []), $school);
        unset($data['class_level_ids']);

        $data['school_id'] = $school->id;

        $schedule = DB::transaction(function () use ($data, $classLevelIds) {
            $this->validateNoClassSlotConflict($data, $classLevelIds);

            $this->validateNoTeacherConflict(
                teacherId: $data['teacher_id'],
                dayOfWeek: $data['day_of_week'],
                timeSlotId: $data['time_slot_id'],
                academicYearId: $data['academic_year_id'],
                semester: (int) $data['semester'],
            );

            $schedule = TeachingSchedule::make($data);
            $schedule->save();
            $schedule->syncClassLevels($classLevelIds);

            return $schedule;
        });

        return $schedule->load(TeachingSchedule::EAGER_LOAD_RELATIONS);
    }

    public function updateSchedule(TeachingSchedule $teachingSchedule, array $data): TeachingSchedule
    {
        $school = School::activeOrFail();

        $previousClassLevelIds = $teachingSchedule->classLevelIds();

        $newClassLevelIds = array_key_exists('class_level_ids', $data)
            ? $this->resolveClassLevelIds((array) $data['class_level_ids'], $school)
            : $previousClassLevelIds;
        unset($data['class_level_ids']);

        $mergedData = array_merge($teachingSchedule->only([
            'school_id', 'teacher_id', 'day_of_week', 'time_slot_id',
            'academic_year_id', 'semester',
        ]), $data);

        DB::transaction(function () use ($teachingSchedule, $data, $mergedData, $previousClassLevelIds, $newClassLevelIds) {
            $this->validateNoClassSlotConflict($mergedData, $newClassLevelIds, $teachingSchedule->id);

            $this->validateNoTeacherConflict(
                teacherId: $mergedData['teacher_id'],
                dayOfWeek: $mergedData['day_of_week'],
                timeSlotId: $mergedData['time_slot_id'],
                academicYearId: $mergedData['academic_year_id'],
                semester: (int) $mergedData['semester'],
                excludeScheduleId: $teachingSchedule->id,
            );

            $teachingSchedule->fill($data);
            $this->recordTeacherHistoryWhenAssignmentChanges($teachingSchedule, $previousClassLevelIds, $newClassLevelIds);
            $teachingSchedule->save();
            $teachingSchedule->syncClassLevels($newClassLevelIds);
        });

        return $teachingSchedule->fresh()->load(TeachingSchedule::EAGER_LOAD_RELATIONS);
    }

    /**
     * The chosen Kelas, ordered as the Kelas master orders them, so a
     * schedule names its Kelas the same way on every screen and in every
     * message. Every Kelas must belong to the active school.
     *
     * @param  array<int, string>  $classLevelIds
     * @return array<int, string>
     */
    private function resolveClassLevelIds(array $classLevelIds, School $school): array
    {
        $chosenIds = array_values(array_unique(array_filter($classLevelIds)));

        if ($chosenIds === []) {
            throw ValidationException::withMessages([
                'class_level_ids' => 'Pilih minimal satu kelas untuk jadwal ini.',
            ]);
        }

        $ownedIds = ClassLevel::query()
            ->where('school_id', $school->id)
            ->whereIn('id', $chosenIds)
            ->inMasterOrder()
            ->pluck('id')
            ->all();

        if (count($ownedIds) !== count($chosenIds)) {
            throw ValidationException::withMessages([
                'class_level_ids' => 'Kelas yang dipilih tidak ada di pesantren ini.',
            ]);
        }

        return $ownedIds;
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
            ->with('classLevels:id')
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
                // A combined schedule is carried whole (ADR 0006), so it is
                // skipped when ANY of its Kelas is already busy in the target.
                $classLevelIds = $source->classLevelIds();

                $busyClassLevelIds = $this->classLevelIdsBusyInSlot([
                    'school_id' => $school->id,
                    'academic_year_id' => $data['target_academic_year_id'],
                    'semester' => (int) $data['target_semester'],
                    'day_of_week' => $source->day_of_week,
                    'time_slot_id' => $source->time_slot_id,
                ], $classLevelIds);

                // Check for teacher conflict in target
                $teacherConflict = TeachingSchedule::where('teacher_id', $source->teacher_id)
                    ->where('day_of_week', $source->day_of_week)
                    ->where('time_slot_id', $source->time_slot_id)
                    ->where('academic_year_id', $data['target_academic_year_id'])
                    ->where('semester', (int) $data['target_semester'])
                    ->where('is_active', true)
                    ->exists();

                if ($busyClassLevelIds !== [] || $teacherConflict) {
                    $skipped++;
                    $skippedDetails[] = [
                        'source_id' => $source->id,
                        'reason' => $busyClassLevelIds !== [] ? 'class_slot_conflict' : 'teacher_conflict',
                        // The same Kelas the form's rejection names, so a skipped
                        // row can say which Kelas was already busy.
                        'conflicting_class' => $busyClassLevelIds !== []
                            ? $this->labelOfClassLevels($busyClassLevelIds)
                            : null,
                    ];

                    continue;
                }

                $clonedSchedule = TeachingSchedule::make([
                    'school_id' => $school->id,
                    'academic_year_id' => $data['target_academic_year_id'],
                    'semester' => (int) $data['target_semester'],
                    'day_of_week' => $source->day_of_week,
                    'time_slot_id' => $source->time_slot_id,
                    'subject_book_id' => $source->subject_book_id,
                    'teacher_id' => $source->teacher_id,
                    'is_active' => true,
                ]);
                $clonedSchedule->save();
                $clonedSchedule->syncClassLevels($classLevelIds);

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

        $schedules = $query->with('classLevels:id')->get();

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
                        'conflicting_class' => $conflict->classLevelsLabel() ?: null,
                    ];

                    continue;
                }

                $classLevelIds = $schedule->classLevelIds();

                $schedule->teacher_id = $data['target_teacher_id'];
                $this->recordTeacherHistoryWhenAssignmentChanges($schedule, $classLevelIds, $classLevelIds);
                $schedule->save();
                $updated++;
            }
        });

        return [
            'updated' => $updated,
            'conflicts' => $conflicts,
            'total' => $schedules->count(),
        ];
    }

    /**
     * Riwayat pengajar (ADR 0005): when the pending (unsaved) changes of the
     * schedule touch its Ustadz, Kitab or its set of Kelas, record the values
     * it had before them in its Semester Akademik — one row per Kelas — so
     * the Cakupan Mengajar of the previous Ustadz keeps every Kelas × Kitab
     * he had for the semester. Dropping one Kelas of a combined schedule
     * records that Kelas alone; a day or time change records nothing. Call
     * inside the transaction that saves the schedule.
     *
     * @param  array<int, string>  $previousClassLevelIds
     * @param  array<int, string>  $newClassLevelIds
     */
    private function recordTeacherHistoryWhenAssignmentChanges(
        TeachingSchedule $teachingSchedule,
        array $previousClassLevelIds,
        array $newClassLevelIds,
    ): void {
        $teacherOrBookChanged = $teachingSchedule->isDirty(self::TEACHER_AND_BOOK_ATTRIBUTES);

        // A new Ustadz or Kitab ends the previous pairing for every Kelas the
        // schedule held; an unchanged pairing only ends for the Kelas dropped.
        $recordedClassLevelIds = $teacherOrBookChanged
            ? $previousClassLevelIds
            : array_diff($previousClassLevelIds, $newClassLevelIds);

        foreach ($recordedClassLevelIds as $classLevelId) {
            TeachingScheduleTeacherHistory::create([
                'school_id' => $teachingSchedule->getOriginal('school_id'),
                'teaching_schedule_id' => $teachingSchedule->id,
                'academic_year_id' => $teachingSchedule->getOriginal('academic_year_id'),
                'semester' => $teachingSchedule->getOriginal('semester'),
                'previous_teacher_id' => $teachingSchedule->getOriginal('teacher_id'),
                'previous_class_level_id' => $classLevelId,
                'previous_subject_book_id' => $teachingSchedule->getOriginal('subject_book_id'),
                'changed_by' => auth()->id(),
            ]);
        }
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

        return $query->with('classLevels:id,label')->first();
    }

    /**
     * One Kelas, one schedule per slot — through a combined schedule too
     * (ADR 0006). The partial unique index only covered the single Kelas
     * column that is now gone, so this is the rule's only guard; callers run
     * it inside the transaction that writes the schedule.
     *
     * @param  array<string, mixed>  $slot
     * @param  array<int, string>  $classLevelIds
     */
    private function validateNoClassSlotConflict(array $slot, array $classLevelIds, ?string $excludeScheduleId = null): void
    {
        $busyClassLevelIds = $this->classLevelIdsBusyInSlot($slot, $classLevelIds, $excludeScheduleId);

        if ($busyClassLevelIds === []) {
            return;
        }

        throw ValidationException::withMessages([
            'class_level_ids' => 'Kelas '.$this->labelOfClassLevels($busyClassLevelIds)
                .' sudah memiliki jadwal lain pada hari dan jam yang sama.',
        ]);
    }

    /**
     * Which of these Kelas another active schedule already holds in that
     * slot — the one reading of "the Kelas is busy", shared by the form
     * (which refuses the save) and by the semester clone (which skips the
     * row). The `school_id` predicate sits on the join table so its
     * (school_id, class_level_id) index is the one used.
     *
     * Every caller runs inside the transaction that writes the schedule, so
     * the claimed Kelas rows are locked first: without the partial unique
     * index the database no longer serialises two writers claiming the same
     * Kelas for the same slot, and this lock does (PostgreSQL; SQLite runs
     * one writer at a time and ignores it).
     *
     * @param  array<string, mixed>  $slot  school_id, academic_year_id, semester, day_of_week, time_slot_id
     * @param  array<int, string>  $classLevelIds
     * @return array<int, string> the busy Kelas, in no particular order — labelOfClassLevels() puts them in the Kelas master order
     */
    private function classLevelIdsBusyInSlot(array $slot, array $classLevelIds, ?string $excludeScheduleId = null): array
    {
        ClassLevel::query()->whereIn('id', $classLevelIds)->lockForUpdate()->pluck('id');

        $query = DB::table('teaching_schedule_class_levels as schedule_class_level')
            ->join('teaching_schedules', 'teaching_schedules.id', '=', 'schedule_class_level.teaching_schedule_id')
            ->where('schedule_class_level.school_id', $slot['school_id'])
            ->whereIn('schedule_class_level.class_level_id', $classLevelIds)
            ->where('teaching_schedules.academic_year_id', $slot['academic_year_id'])
            ->where('teaching_schedules.semester', (int) $slot['semester'])
            ->where('teaching_schedules.day_of_week', $slot['day_of_week'])
            ->where('teaching_schedules.time_slot_id', $slot['time_slot_id'])
            ->where('teaching_schedules.is_active', true);

        if ($excludeScheduleId) {
            $query->where('teaching_schedules.id', '!=', $excludeScheduleId);
        }

        return $query->distinct()->pluck('schedule_class_level.class_level_id')->all();
    }

    /**
     * These Kelas named the one way every screen and message names them.
     *
     * @param  array<int, string>  $classLevelIds
     */
    private function labelOfClassLevels(array $classLevelIds): string
    {
        return ClassLevel::joinedLabel(
            ClassLevel::query()->whereIn('id', $classLevelIds)->inMasterOrder()->get()
        );
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
                'teacher_id' => 'Ustadz ini sudah mengajar '.$conflict->classLevelsLabel().' pada hari dan jam yang sama.',
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
                'name' => $teacher->school?->name,
                'address' => $teacher->school?->address,
                'phone' => $teacher->school?->phone,
                'email' => $teacher->school?->email,
            ],
            'teacher' => [
                'full_name' => $teacher->full_name,
                'code' => $teacher->code,
            ],
            'academic_year' => [
                'name' => $academicYearName,
            ],
            'semester' => $semester,
            'schedules_sorted' => $sortedSchedules->all(),
            'schedules_by_day' => $schedulesByDay,
            'time_slots' => $timeSlots,
            'totals' => [
                'sesi' => $sortedSchedules->count(),
                'kitab' => $sortedSchedules->pluck('subject_book_id')->unique()->count(),
                'kelas' => $sortedSchedules
                    ->flatMap(fn (TeachingSchedule $schedule) => $schedule->classLevelIds())
                    ->unique()
                    ->count(),
            ],
            'logo_data_uri' => $this->schoolLogoResolver->dataUri($teacher->school),
            'day_labels' => self::DAY_LABELS,
            'generated_at' => Carbon::now('Asia/Jakarta'),
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
                'day' => $day,
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
                'id' => $slotId,
                'label' => $schedule->timeSlot?->label ?? $slotId,
                'sort_order' => $schedule->timeSlot?->sort_order ?? PHP_INT_MAX,
            ];
        }

        $slots = array_values($seen);
        usort($slots, fn ($a, $b) => $a['sort_order'] <=> $b['sort_order']);

        return $slots;
    }
}
