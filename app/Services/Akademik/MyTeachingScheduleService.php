<?php

namespace App\Services\Akademik;

use App\Models\AcademicYear;
use App\Models\TeachingSchedule;
use App\Models\User;
use App\Services\AcademicYearService;
use App\Services\TeachingScheduleService;
use Illuminate\Database\Eloquent\Collection;

/**
 * Jadwal Saya: the Jadwal Mengajar the Ustadz linked to the current user
 * holds NOW in one Semester Akademik — the active one unless another is
 * chosen. Whatever the user's permissions, it is always his own schedule
 * (a pengurus without a linked Ustadz gets an empty list), never the
 * school's whole schedule.
 *
 * Not the Cakupan Mengajar: the Absensi Pertemuan list
 * (ClassSessionService::listSchedulesForAttendance) also holds the
 * schedules moved away from him that semester (riwayat pengajar, ADR
 * 0005); Jadwal Saya does not, like the Alert Pertemuan Bolong.
 */
class MyTeachingScheduleService
{
    public function __construct(
        private AcademicYearService $academicYearService,
        private TeachingScheduleService $teachingScheduleService,
    ) {}

    /**
     * @return array{academic_year: array{id: string, name: string}|null, semester: int|null, schedules: Collection<int, TeachingSchedule>|array{}}
     */
    public function listForCurrentUser(?string $academicYearId, ?int $semester): array
    {
        if ($academicYearId === null || $semester === null) {
            $activeAcademicYear = $this->academicYearService->getActive();

            if ($activeAcademicYear === null) {
                return ['academic_year' => null, 'semester' => null, 'schedules' => []];
            }

            $academicYear = $activeAcademicYear;
            $semester = $activeAcademicYear->active_semester;
        } else {
            $academicYear = AcademicYear::findOrFail($academicYearId);
        }

        /** @var User $user */
        $user = auth()->user();
        $linkedTeacherId = $user->teacher?->id;

        return [
            'academic_year' => ['id' => $academicYear->id, 'name' => $academicYear->name],
            'semester' => $semester,
            'schedules' => $linkedTeacherId === null
                ? []
                : $this->teachingScheduleService->listActiveSchedulesHeldBy($linkedTeacherId, $academicYear->id, $semester),
        ];
    }
}
