<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The pesantren's single business "today": Asia/Jakarta (WIB), never the
 * app timezone (UTC). Extracted so SessionDatePolicy, MissingSessionFinder
 * and ClassSessionService::cancelDateRange share one definition instead of
 * each freezing their own (global constraint "Today in WIB"). Tests freeze
 * it with Carbon::setTestNow().
 */
final class BusinessDate
{
    public const TIMEZONE = 'Asia/Jakarta';

    public static function todayString(): string
    {
        return Carbon::now(self::TIMEZONE)->toDateString();
    }

    public static function yesterdayString(): string
    {
        return Carbon::now(self::TIMEZONE)->subDay()->toDateString();
    }

    public static function daysAgoString(int $days): string
    {
        return Carbon::now(self::TIMEZONE)->subDays($days)->toDateString();
    }
}
