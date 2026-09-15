<?php

use App\Services\Akademik\Calculation\AttendanceScoreCalculator;

test('present divided by present plus absent, rounded to 2 decimals', function () {
    expect((new AttendanceScoreCalculator)->calculate(7, 3))->toBe(70.0);
    expect((new AttendanceScoreCalculator)->calculate(2, 1))->toBe(66.67);
});

test('a perfect record scores 100', function () {
    expect((new AttendanceScoreCalculator)->calculate(10, 0))->toBe(100.0);
});

test('an empty denominator (no present and no absent) is NULL, never 0', function () {
    expect((new AttendanceScoreCalculator)->calculate(0, 0))->toBeNull();
});

test('all absences scores 0', function () {
    expect((new AttendanceScoreCalculator)->calculate(0, 5))->toBe(0.0);
});
