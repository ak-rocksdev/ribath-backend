<?php

namespace App\Services\Akademik;

use App\Models\AcademicSemester;
use App\Models\TeachingSchedule;
use App\Support\BusinessDate;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Which dates a Pertemuan may be recorded, edited or cancelled on (spec
 * §Pertemuan, user stories 39–40, 47–48):
 *
 *  - everyone: the date falls on the schedule's weekday, and inside the
 *    semester range when its start_date / end_date are set;
 *  - non-super_admin: the date is not in the future, and an EXISTING
 *    session may only be changed while its date is at most
 *    ATTENDANCE_EDIT_WINDOW_DAYS back (recording a missed session older
 *    than that is still allowed — it is not an edit);
 *  - super_admin: any date inside the semester, including future dates
 *    and old sessions, with `requiresOverrideWarning()` telling the UI to
 *    warn.
 *
 * "Today" is the pesantren's business date in WIB (Asia/Jakarta), not the
 * app timezone (UTC): a Ba'da Subuh session at 05:45 WIB is still "today"
 * although UTC is on the previous day (global constraint "Today in WIB";
 * precedent: Keuangan requests, Bill model). Tests freeze it with
 * Carbon::setTestNow(). Pure: it only reads the attributes of the models
 * it is given, never queries.
 */
final class SessionDatePolicy
{
    public const ATTENDANCE_EDIT_WINDOW_DAYS = 14;

    public const BUSINESS_TIMEZONE = BusinessDate::TIMEZONE;

    public const MESSAGE_WEEKDAY_MISMATCH = 'Tanggal tidak sesuai hari jadwal.';

    public const MESSAGE_OUTSIDE_SEMESTER = 'Tanggal di luar rentang semester.';

    public const MESSAGE_FUTURE_DATE = 'Pertemuan tidak boleh dicatat untuk tanggal mendatang.';

    public const MESSAGE_EDIT_WINDOW = 'Perubahan absensi hanya boleh sampai 14 hari ke belakang.';

    /**
     * The first rule the date breaks, as a user-facing message, or null
     * when the date is allowed.
     */
    public function violationFor(
        TeachingSchedule $schedule,
        CarbonInterface $sessionDate,
        ?AcademicSemester $academicSemester,
        bool $actorIsSuperAdmin,
        bool $isEditingExistingSession,
    ): ?string {
        if (! $this->matchesScheduleWeekday($schedule, $sessionDate)) {
            return self::MESSAGE_WEEKDAY_MISMATCH;
        }

        if (! $this->isWithinSemester($sessionDate, $academicSemester)) {
            return self::MESSAGE_OUTSIDE_SEMESTER;
        }

        return $this->actorDateViolation($sessionDate, $actorIsSuperAdmin, $isEditingExistingSession);
    }

    /**
     * For changing the attendances of an existing session: its weekday and
     * semester were checked when it was recorded (and must not start
     * failing if the schedule's day changes later), so only the acting
     * user's limits apply — no future date and the edit window for a
     * non-super_admin.
     */
    public function violationForAttendanceEdit(CarbonInterface $sessionDate, bool $actorIsSuperAdmin): ?string
    {
        return $this->actorDateViolation($sessionDate, $actorIsSuperAdmin, true);
    }

    /**
     * @throws ValidationException keyed "session_date"
     */
    public function assertAllowed(
        TeachingSchedule $schedule,
        CarbonInterface $sessionDate,
        ?AcademicSemester $academicSemester,
        bool $actorIsSuperAdmin,
        bool $isEditingExistingSession,
    ): void {
        $violation = $this->violationFor($schedule, $sessionDate, $academicSemester, $actorIsSuperAdmin, $isEditingExistingSession);

        if ($violation !== null) {
            throw ValidationException::withMessages(['session_date' => $violation]);
        }
    }

    /**
     * @throws ValidationException keyed "session_date"
     */
    public function assertAttendanceEditAllowed(CarbonInterface $sessionDate, bool $actorIsSuperAdmin): void
    {
        $violation = $this->violationForAttendanceEdit($sessionDate, $actorIsSuperAdmin);

        if ($violation !== null) {
            throw ValidationException::withMessages(['session_date' => $violation]);
        }
    }

    /**
     * True when a super_admin acts beyond what everyone else may do: a
     * future date, or changing an existing session older than the edit
     * window. The UI shows a warning before saving and after.
     */
    public function requiresOverrideWarning(CarbonInterface $sessionDate, bool $actorIsSuperAdmin, bool $isEditingExistingSession): bool
    {
        if (! $actorIsSuperAdmin) {
            return false;
        }

        return $this->isFutureDate($sessionDate)
            || ($isEditingExistingSession && ! $this->isInsideEditWindow($sessionDate));
    }

    /**
     * teaching_schedules.day_of_week holds English lowercase day names.
     */
    public function matchesScheduleWeekday(TeachingSchedule $schedule, CarbonInterface $sessionDate): bool
    {
        return strtolower($sessionDate->englishDayOfWeek) === $schedule->day_of_week;
    }

    public function isWithinSemester(CarbonInterface $sessionDate, ?AcademicSemester $academicSemester): bool
    {
        if ($academicSemester === null) {
            return true;
        }

        $sessionDateString = $sessionDate->toDateString();

        if ($academicSemester->start_date !== null && $sessionDateString < $academicSemester->start_date->toDateString()) {
            return false;
        }

        return $academicSemester->end_date === null || $sessionDateString <= $academicSemester->end_date->toDateString();
    }

    public function isFutureDate(CarbonInterface $sessionDate): bool
    {
        return $sessionDate->toDateString() > $this->businessToday();
    }

    /**
     * The window is inclusive: today − 14 days is still editable.
     */
    public function isInsideEditWindow(CarbonInterface $sessionDate): bool
    {
        return $sessionDate->toDateString() >= BusinessDate::daysAgoString(self::ATTENDANCE_EDIT_WINDOW_DAYS);
    }

    /**
     * Libur massal (Task 11) is a planning action: a future date is always
     * fine for everyone (holidays are announced ahead of time), but a
     * non-super_admin still may not declare a NEW cancelled session for a
     * past date older than the edit window. A super_admin is unrestricted
     * within the semester (checked separately by the caller).
     */
    public function isPastEditWindowForRangeCancel(CarbonInterface $sessionDate, bool $actorIsSuperAdmin): bool
    {
        if ($actorIsSuperAdmin || $this->isFutureDate($sessionDate)) {
            return false;
        }

        return ! $this->isInsideEditWindow($sessionDate);
    }

    /**
     * Today's date in WIB, as 'Y-m-d'.
     */
    private function businessToday(): string
    {
        return BusinessDate::todayString();
    }

    private function actorDateViolation(CarbonInterface $sessionDate, bool $actorIsSuperAdmin, bool $isEditingExistingSession): ?string
    {
        if ($actorIsSuperAdmin) {
            return null;
        }

        if ($this->isFutureDate($sessionDate)) {
            return self::MESSAGE_FUTURE_DATE;
        }

        if ($isEditingExistingSession && ! $this->isInsideEditWindow($sessionDate)) {
            return self::MESSAGE_EDIT_WINDOW;
        }

        return null;
    }
}
