<?php

use App\Services\Akademik\Calculation\MemorizationFactorCalculator;

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
