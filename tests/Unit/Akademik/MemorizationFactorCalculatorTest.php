<?php

use App\Services\Akademik\Calculation\MemorizationFactorCalculator;
use App\Services\Akademik\Calculation\MemorizationFactorResult;

test('newPages divided by targetPages, rounded to 2 decimals', function () {
    expect((new MemorizationFactorCalculator)->calculateTargetAchievement(40.0, 20.0))->toBe(50.0);
    expect((new MemorizationFactorCalculator)->calculateTargetAchievement(30.0, 10.0))->toBe(33.33);
});

test('achievement is capped at 100 when newPages exceeds targetPages', function () {
    expect((new MemorizationFactorCalculator)->calculateTargetAchievement(20.0, 50.0))->toBe(100.0);
});

test('exactly meeting the target scores 100', function () {
    expect((new MemorizationFactorCalculator)->calculateTargetAchievement(20.0, 20.0))->toBe(100.0);
});

test('a NULL target is NULL, never 0', function () {
    expect((new MemorizationFactorCalculator)->calculateTargetAchievement(null, 10.0))->toBeNull();
});

test('a target of 0 or less is NULL, never 0', function () {
    expect((new MemorizationFactorCalculator)->calculateTargetAchievement(0.0, 10.0))->toBeNull();
    expect((new MemorizationFactorCalculator)->calculateTargetAchievement(-5.0, 10.0))->toBeNull();
});

test('zero newPages against a real target scores 0, not NULL', function () {
    expect((new MemorizationFactorCalculator)->calculateTargetAchievement(40.0, 0.0))->toBe(0.0);
});

// ── calculate(): the three auto_from_log Tahfizh factors together (§4.3) ──

test('calculate() combines target achievement, submission quality and review quality', function () {
    $result = (new MemorizationFactorCalculator)->calculate(40.0, 20.0, [80, 90], [70, 90]);

    expect($result)->toBeInstanceOf(MemorizationFactorResult::class);
    expect($result->targetAchievement)->toBe(50.0);
    expect($result->submissionQuality)->toBe(85.0);
    expect($result->reviewQuality)->toBe(80.0);
});

test('calculate() rounds each average to 2 decimals', function () {
    $result = (new MemorizationFactorCalculator)->calculate(30.0, 10.0, [80, 85, 90], [100]);

    expect($result->targetAchievement)->toBe(33.33);
    expect($result->submissionQuality)->toBe(85.0);
    expect($result->reviewQuality)->toBe(100.0);
});

test('calculate() is NULL, never 0, for a factor with no scores', function () {
    $result = (new MemorizationFactorCalculator)->calculate(null, 0.0, [], []);

    expect($result->targetAchievement)->toBeNull();
    expect($result->submissionQuality)->toBeNull();
    expect($result->reviewQuality)->toBeNull();
});

test('calculate() submission quality is independent of review quality and vice versa', function () {
    $result = (new MemorizationFactorCalculator)->calculate(40.0, 20.0, [80], []);

    expect($result->submissionQuality)->toBe(80.0);
    expect($result->reviewQuality)->toBeNull();
});

test('MemorizationFactorResult::toArray() exposes all three fields', function () {
    $result = (new MemorizationFactorCalculator)->calculate(40.0, 20.0, [80, 90], [70, 90]);

    expect($result->toArray())->toBe([
        'target_achievement' => 50.0,
        'submission_quality' => 85.0,
        'review_quality' => 80.0,
    ]);
});
