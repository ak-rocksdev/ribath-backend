<?php

namespace App\Services\Akademik\Calculation;

use App\Models\Student;
use Carbon\CarbonInterface;

/**
 * Whether a santri is expected on a date — for a Tugas (its task_date) or a
 * Pertemuan (its session_date): false when the date is before the santri's
 * entry_date. A santri who joins the class later is not expected for
 * anything that happened before they arrived (spec rule G16 / user story
 * 46: nothing before a santri's entry_date is expected of them). Strict: a
 * santri who enters on the date itself is expected, consistent with
 * MidtermExclusionRule's UTS-date comparison. A santri with no entry_date
 * is always expected (nothing to compare against).
 *
 * Shared by:
 *  - TaskFactorScoreProvider / ClassTaskService (a task dated before the
 *    santri's entry is excluded from the Tugas average, shown "Belum masuk",
 *    and a submitted score for it is rejected);
 *  - ClassSessionService (a santri is expected at a Pertemuan only when
 *    entered on or before its session_date; a posted attendance row for a
 *    santri who entered later is rejected).
 *
 * Pure: it only reads the attributes of the models it is given, never queries.
 */
final class EnrollmentDateRule
{
    public function isExpectedOn(Student $student, CarbonInterface $date): bool
    {
        if ($student->entry_date === null) {
            return true;
        }

        return $date->toDateString() >= $student->entry_date->toDateString();
    }
}
