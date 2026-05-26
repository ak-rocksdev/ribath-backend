<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// US3 — Daily bill generator. Runs at 00:01 Asia/Jakarta. Idempotent so
// re-runs in the same period are safe (firstOrCreate per unique pair).
Schedule::command('fee:generate-bills')
    ->dailyAt('00:01')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();
