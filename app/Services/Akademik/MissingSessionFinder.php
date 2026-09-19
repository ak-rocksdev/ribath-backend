<?php

namespace App\Services\Akademik;

use App\Models\AcademicSemester;
use App\Models\ClassSession;
use App\Models\School;
use App\Models\TeachingSchedule;
use App\Services\AcademicYearService;
use App\Support\BusinessDate;
use App\Support\ScheduleDateRange;
use Illuminate\Support\Carbon;

/**
 * Alert Pertemuan Bolong (Task 11): a Pertemuan terjadwal whose date has
 * passed without ever being recorded, held or cancelled (glossary
 * "Pertemuan Bolong"). "Cancelled" (Pertemuan Dibatalkan) does not count
 * as bolong — it was a deliberate decision, not a gap.
 *
 * For each active teaching schedule of the (academic_year_id, semester)
 * pair, every date matching its weekday from max(semester start_date,
 * the schedule's own created_at in WIB — it cannot be missing before it
 * existed) through min(semester end_date, yesterday in WIB — a session
 * dated today is never bolong) is "missing" unless a class_sessions row
 * (held or cancelled, not soft-deleted) already exists for it. Sessions
 * are loaded once for the whole semester and diffed in memory rather than
 * queried per date.
 *
 * A user holding only `view-own-attendance` (Akun Ustadz, ADR 0004) is
 * alerted for the schedules his Ustadz holds NOW — not the Cakupan
 * Mengajar, whose riwayat pengajar would alert the former Ustadz of a
 * moved schedule (ADR 0005). `view-attendance` sees every Ustadz.
 */
class MissingSessionFinder
{
    public const REASON_NO_ACTIVE_ACADEMIC_YEAR = 'no_active_academic_year';

    public const REASON_SEMESTER_DATES_MISSING = 'semester_dates_missing';

    public const MAX_ITEMS_PER_TEACHER = 50;

    public function __construct(
        private AcademicYearService $academicYearService,
        private TeachingScopeResolver $teachingScopeResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function findForSemester(?string $academicYearId, ?int $semester): array
    {
        $school = School::activeOrFail();
        $alertedTeacherIds = $this->teachingScopeResolver->currentScheduleTeacherIdsForCurrentUser('view-attendance');

        if ($academicYearId === null || $semester === null) {
            $activeAcademicYear = $this->academicYearService->getActive();

            if ($activeAcademicYear === null) {
                return $this->notConfigured(self::REASON_NO_ACTIVE_ACADEMIC_YEAR, null, null);
            }

            $academicYearId = $activeAcademicYear->id;
            $semester = $activeAcademicYear->active_semester;
        }

        $academicSemester = AcademicSemester::findByPair($academicYearId, $semester);

        if ($academicSemester === null || $academicSemester->start_date === null || $academicSemester->end_date === null) {
            return $this->notConfigured(self::REASON_SEMESTER_DATES_MISSING, $academicYearId, $semester);
        }

        $semesterStart = $academicSemester->start_date->toDateString();
        $semesterEnd = $academicSemester->end_date->toDateString();
        $businessYesterday = BusinessDate::yesterdayString();
        $enumerationEnd = $businessYesterday < $semesterEnd ? $businessYesterday : $semesterEnd;

        $schedules = TeachingSchedule::query()
            ->where('school_id', $school->id)
            ->where('academic_year_id', $academicYearId)
            ->where('semester', $semester)
            ->where('is_active', true)
            ->when($alertedTeacherIds !== null, fn ($query) => $query->whereIn('teacher_id', $alertedTeacherIds))
            ->with(['classLevels:id,label', 'subjectBook:id,title', 'timeSlot:id,label', 'teacher:id,full_name'])
            ->get();

        if ($schedules->isEmpty() || $enumerationEnd < $semesterStart) {
            return [
                'configured' => true,
                'academic_year_id' => $academicYearId,
                'semester' => $semester,
                'total_missing' => 0,
                'teachers' => [],
            ];
        }

        $coveredKeys = ClassSession::query()
            ->whereIn('teaching_schedule_id', $schedules->pluck('id'))
            ->whereDate('session_date', '>=', $semesterStart)
            ->whereDate('session_date', '<=', $enumerationEnd)
            ->get(['teaching_schedule_id', 'session_date'])
            ->map(fn (ClassSession $session) => $session->teaching_schedule_id.'|'.$session->session_date->toDateString())
            ->flip();

        $entriesByTeacherId = [];

        foreach ($schedules as $schedule) {
            $scheduleStart = $this->scheduleEnumerationStart($schedule, $semesterStart);

            if ($scheduleStart > $enumerationEnd) {
                continue;
            }

            foreach (ScheduleDateRange::datesMatchingWeekday($schedule->day_of_week, $scheduleStart, $enumerationEnd) as $sessionDate) {
                if ($coveredKeys->has($schedule->id.'|'.$sessionDate)) {
                    continue;
                }

                $entriesByTeacherId[$schedule->teacher_id]['teacher'] ??= [
                    'id' => $schedule->teacher?->id,
                    'full_name' => $schedule->teacher?->full_name,
                ];
                $entriesByTeacherId[$schedule->teacher_id]['items'][] = [
                    'teaching_schedule_id' => $schedule->id,
                    'session_date' => $sessionDate,
                    // One alert per schedule and date, naming every Kelas of a
                    // combined schedule (ADR 0006).
                    'class_levels' => $schedule->classLevels
                        ->map(fn ($classLevel) => ['id' => $classLevel->id, 'label' => $classLevel->label])
                        ->all(),
                    'subject_book' => $schedule->subjectBook ? [
                        'id' => $schedule->subjectBook->id,
                        'title' => $schedule->subjectBook->title,
                    ] : null,
                    'time_slot' => $schedule->timeSlot ? [
                        'id' => $schedule->timeSlot->id,
                        'label' => $schedule->timeSlot->label,
                    ] : null,
                ];
            }
        }

        $teachers = $this->presentTeachers($entriesByTeacherId);

        return [
            'configured' => true,
            'academic_year_id' => $academicYearId,
            'semester' => $semester,
            'total_missing' => array_sum(array_column($teachers, 'missing_count')),
            'teachers' => $teachers,
        ];
    }

    /**
     * @param  array<string, array{teacher: array<string, mixed>, items: array<int, array<string, mixed>>}>  $entriesByTeacherId
     * @return array<int, array<string, mixed>>
     */
    private function presentTeachers(array $entriesByTeacherId): array
    {
        $teachers = array_map(function (array $entry) {
            $items = $entry['items'];
            usort($items, fn (array $a, array $b) => $a['session_date'] <=> $b['session_date']);

            return [
                'teacher' => $entry['teacher'],
                'missing_count' => count($items),
                'items' => array_slice($items, 0, self::MAX_ITEMS_PER_TEACHER),
            ];
        }, array_values($entriesByTeacherId));

        usort($teachers, function (array $a, array $b) {
            $byCountDesc = $b['missing_count'] <=> $a['missing_count'];

            if ($byCountDesc !== 0) {
                return $byCountDesc;
            }

            return ($a['teacher']['full_name'] ?? '') <=> ($b['teacher']['full_name'] ?? '');
        });

        return $teachers;
    }

    /**
     * The schedule cannot be missing a Pertemuan before it existed: its
     * enumeration starts at the later of the semester start and its own
     * created_at, read as a WIB calendar date (global constraint "Today
     * in WIB").
     */
    private function scheduleEnumerationStart(TeachingSchedule $schedule, string $semesterStart): string
    {
        $scheduleCreatedDate = Carbon::parse($schedule->created_at)
            ->setTimezone(BusinessDate::TIMEZONE)
            ->toDateString();

        return $scheduleCreatedDate > $semesterStart ? $scheduleCreatedDate : $semesterStart;
    }

    /**
     * @return array<string, mixed>
     */
    private function notConfigured(string $reason, ?string $academicYearId, ?int $semester): array
    {
        return [
            'configured' => false,
            'reason' => $reason,
            'academic_year_id' => $academicYearId,
            'semester' => $semester,
            'total_missing' => 0,
            'teachers' => [],
        ];
    }
}
