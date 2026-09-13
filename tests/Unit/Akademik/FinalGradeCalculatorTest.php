<?php

use App\Services\Akademik\Calculation\FinalGradeCalculator;
use App\Services\Akademik\Calculation\FinalGradeResult;

test('final score is the sum of score times normalized weight', function () {
    $result = (new FinalGradeCalculator)->calculate(
        ['uts' => 80.0, 'uas' => 90.0, 'tugas' => 70.0, 'keaktifan' => 85.0, 'adab' => 100.0, 'absensi' => 75.0],
        ['uts' => 20.0, 'uas' => 30.0, 'tugas' => 20.0, 'keaktifan' => 10.0, 'adab' => 10.0, 'absensi' => 10.0],
    );

    expect($result)->toBeInstanceOf(FinalGradeResult::class);
    // 16 + 27 + 14 + 8.5 + 10 + 7.5
    expect($result->finalScore)->toBe(83.0);
    expect($result->missingFactorCodes)->toBe([]);
    expect($result->isComplete())->toBeTrue();
});

test('the worked example without UTS uses the normalized weights', function () {
    $result = (new FinalGradeCalculator)->calculate(
        ['uas' => 90.0, 'tugas' => 70.0, 'keaktifan' => 85.0, 'adab' => 100.0, 'absensi' => 75.0],
        ['uas' => 37.5, 'tugas' => 25.0, 'keaktifan' => 12.5, 'adab' => 12.5, 'absensi' => 12.5],
    );

    // 33.75 + 17.5 + 10.625 + 12.5 + 9.375
    expect($result->finalScore)->toBe(83.75);
});

test('a single factor with the whole weight yields its own score', function () {
    $result = (new FinalGradeCalculator)->calculate(['uas' => 72.5], ['uas' => 100.0]);

    expect($result->finalScore)->toBe(72.5);
    expect($result->isComplete())->toBeTrue();
});

test('any missing active factor makes the final score NULL and is listed', function () {
    $result = (new FinalGradeCalculator)->calculate(
        ['uts' => 80.0, 'uas' => null, 'tugas' => 70.0, 'absensi' => null],
        ['uts' => 25.0, 'uas' => 25.0, 'tugas' => 25.0, 'absensi' => 25.0],
    );

    expect($result->finalScore)->toBeNull();
    expect($result->missingFactorCodes)->toBe(['uas', 'absensi']);
    expect($result->isComplete())->toBeFalse();
});

test('a weighted code absent from the scores counts as missing', function () {
    $result = (new FinalGradeCalculator)->calculate(['uts' => 80.0], ['uts' => 50.0, 'uas' => 50.0]);

    expect($result->finalScore)->toBeNull();
    expect($result->missingFactorCodes)->toBe(['uas']);
});

test('zero is a real score, not a missing one', function () {
    $result = (new FinalGradeCalculator)->calculate(['uts' => 0.0, 'uas' => 80.0], ['uts' => 50.0, 'uas' => 50.0]);

    expect($result->finalScore)->toBe(40.0);
    expect($result->missingFactorCodes)->toBe([]);
});

test('scores of factors without a normalized weight are ignored', function () {
    // An inactive/disabled factor (e.g. UTS for a late student) may still carry a score.
    $result = (new FinalGradeCalculator)->calculate(['uts' => 10.0, 'uas' => 80.0], ['uas' => 100.0]);

    expect($result->finalScore)->toBe(80.0);
    expect($result->factorScores)->toBe(['uas' => 80.0]);
});

test('the final score is rounded to two decimals half up', function (array $scores, array $weights, float $expectedFinalScore) {
    expect((new FinalGradeCalculator)->calculate($scores, $weights)->finalScore)->toBe($expectedFinalScore);
})->with([
    'x.xx5 rounds up' => [['a' => 80.01, 'b' => 80.0], ['a' => 50.0, 'b' => 50.0], 80.01],
    'thirds round down' => [['a' => 70.0, 'b' => 70.0, 'c' => 71.0], ['a' => 100 / 3, 'b' => 100 / 3, 'c' => 100 / 3], 70.33],
    'thirds round up' => [['a' => 70.0, 'b' => 71.0, 'c' => 71.0], ['a' => 100 / 3, 'b' => 100 / 3, 'c' => 100 / 3], 70.67],
    'float noise does not leak' => [['a' => 80.0, 'b' => 80.0, 'c' => 80.0], ['a' => 100 / 3, 'b' => 100 / 3, 'c' => 100 / 3], 80.0],
]);

test('no normalized weights yields no final score and nothing missing', function () {
    $result = (new FinalGradeCalculator)->calculate([], []);

    expect($result->finalScore)->toBeNull();
    expect($result->missingFactorCodes)->toBe([]);
    expect($result->isComplete())->toBeFalse();
});

test('the result carries the full breakdown for snapshots', function () {
    $result = (new FinalGradeCalculator)->calculate(
        ['uts' => 80.0, 'uas' => null],
        ['uts' => 40.0, 'uas' => 60.0],
    );

    expect($result->toArray())->toBe([
        'final_score' => null,
        'is_complete' => false,
        'missing_factor_codes' => ['uas'],
        'normalized_weights' => ['uts' => 40.0, 'uas' => 60.0],
        'factor_scores' => ['uts' => 80.0, 'uas' => null],
    ]);
});
