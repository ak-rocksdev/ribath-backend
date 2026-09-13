<?php

use App\Services\Akademik\Calculation\GradeWeightNormalizer;

/**
 * The default Teori/Kitab weights (v1.0): 20/30/20/10/10/10.
 *
 * @return list<array{code: string, weight: float, is_active: bool}>
 */
function teoriKitabDefaultWeights(): array
{
    return [
        ['code' => 'uts', 'weight' => 20.0, 'is_active' => true],
        ['code' => 'uas', 'weight' => 30.0, 'is_active' => true],
        ['code' => 'tugas', 'weight' => 20.0, 'is_active' => true],
        ['code' => 'keaktifan', 'weight' => 10.0, 'is_active' => true],
        ['code' => 'adab', 'weight' => 10.0, 'is_active' => true],
        ['code' => 'absensi', 'weight' => 10.0, 'is_active' => true],
    ];
}

test('all active factors summing to 100 keep their weights', function () {
    $normalizedWeights = (new GradeWeightNormalizer)->normalize(teoriKitabDefaultWeights());

    expect($normalizedWeights)->toEqual([
        'uts' => 20.0,
        'uas' => 30.0,
        'tugas' => 20.0,
        'keaktifan' => 10.0,
        'adab' => 10.0,
        'absensi' => 10.0,
    ]);
});

test('disabling UTS normalizes 20/30/20/10/10/10 to 37.5/25/12.5/12.5/12.5', function () {
    $normalizedWeights = (new GradeWeightNormalizer)->normalize(teoriKitabDefaultWeights(), ['uts']);

    expect($normalizedWeights)->toEqual([
        'uas' => 37.5,
        'tugas' => 25.0,
        'keaktifan' => 12.5,
        'adab' => 12.5,
        'absensi' => 12.5,
    ]);
});

test('an inactive factor is left out exactly like a disabled one', function () {
    $factors = teoriKitabDefaultWeights();
    $factors[0]['is_active'] = false;

    $normalizer = new GradeWeightNormalizer;

    // One function for both cases (spec §4.2): the result is identical.
    expect($normalizer->normalize($factors))->toEqual($normalizer->normalize(teoriKitabDefaultWeights(), ['uts']));
});

test('normalized weights always sum to 100 even when the stored active sum is not 100', function () {
    $normalizedWeights = (new GradeWeightNormalizer)->normalize([
        ['code' => 'uts', 'weight' => 10, 'is_active' => true],
        ['code' => 'uas', 'weight' => 10, 'is_active' => true],
        ['code' => 'tugas', 'weight' => 10, 'is_active' => true],
    ]);

    expect(array_sum($normalizedWeights))->toEqualWithDelta(100.0, 1e-9);
    // Kept unrounded internally: 33.333…, not 33.33.
    expect($normalizedWeights['uts'])->toEqualWithDelta(100 / 3, 1e-12);
});

test('a single active factor gets the whole weight', function () {
    $normalizedWeights = (new GradeWeightNormalizer)->normalize([
        ['code' => 'uts', 'weight' => 20, 'is_active' => false],
        ['code' => 'uas', 'weight' => 30, 'is_active' => true],
    ]);

    expect($normalizedWeights)->toEqual(['uas' => 100.0]);
});

test('nothing active yields an empty result', function () {
    $normalizer = new GradeWeightNormalizer;

    expect($normalizer->normalize([]))->toBe([]);
    expect($normalizer->normalize([
        ['code' => 'uts', 'weight' => 20, 'is_active' => false],
        ['code' => 'uas', 'weight' => 30, 'is_active' => true],
    ], ['uas']))->toBe([]);
});

test('active factors whose weights are all zero yield an empty result instead of dividing by zero', function () {
    expect((new GradeWeightNormalizer)->normalize([
        ['code' => 'uts', 'weight' => 0, 'is_active' => true],
        ['code' => 'uas', 'weight' => 0, 'is_active' => true],
    ]))->toBe([]);
});

test('decimal-string weights from the database are accepted', function () {
    $normalizedWeights = (new GradeWeightNormalizer)->normalize([
        ['code' => 'uas', 'weight' => '30.00', 'is_active' => true],
        ['code' => 'tugas', 'weight' => '10.00', 'is_active' => true],
    ]);

    expect($normalizedWeights)->toEqual(['uas' => 75.0, 'tugas' => 25.0]);
});
