<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The dates a weekly teaching schedule falls on within a closed date
 * range — shared by MissingSessionFinder (Pertemuan Bolong) and
 * ClassSessionService::cancelDateRange (libur massal) so the "which dates
 * match this schedule's weekday" loop is written once.
 */
final class ScheduleDateRange
{
    /**
     * Every date matching $dayOfWeek (teaching_schedules.day_of_week —
     * English lowercase, e.g. "monday") within [$startDate, $endDate]
     * inclusive, both 'Y-m-d' strings. Empty when the range is empty or
     * contains no matching weekday.
     *
     * @return array<int, string>
     */
    public static function datesMatchingWeekday(string $dayOfWeek, string $startDate, string $endDate): array
    {
        if ($startDate > $endDate) {
            return [];
        }

        $cursor = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();

        while (strtolower($cursor->englishDayOfWeek) !== $dayOfWeek) {
            $cursor->addDay();

            if ($cursor->gt($end)) {
                return [];
            }
        }

        $dates = [];

        while ($cursor->lte($end)) {
            $dates[] = $cursor->toDateString();
            $cursor->addDays(7);
        }

        return $dates;
    }
}
