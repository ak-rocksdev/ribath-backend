<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily bill generator at 00:01 Asia/Jakarta. `--actor=scheduler` is required
// so `BillGeneratorService::shouldGenerate` can block once_at_enrollment
// cadence from the daily cron (enrollment bills are created at PSB approval
// time, not by cron). Idempotent — re-runs in the same period are safe.
Schedule::command('fee:generate-bills --actor=scheduler')
    ->dailyAt('00:01')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();
