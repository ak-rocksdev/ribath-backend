<?php

use App\Models\AcademicSemester;
use App\Models\GradingFactor;
use App\Models\Student;
use App\Services\Akademik\Calculation\MidtermExclusionRule;

/*
 * Models are built with raw attributes so no database connection (or app
 * container) is needed: the rule only reads attributes, it never queries.
 */

function midtermSemester(bool $utsEnabled, ?string $midtermExamDate): AcademicSemester
{
    return (new AcademicSemester)->setRawAttributes([
        'uts_enabled' => $utsEnabled,
        'midterm_exam_date' => $midtermExamDate,
    ]);
}

function studentEnteredOn(?string $entryDate): Student
{
    return (new Student)->setRawAttributes(['entry_date' => $entryDate]);
}

/**
 * @return list<GradingFactor>
 */
function teoriKitabFactors(): array
{
    return [
        (new GradingFactor)->setRawAttributes(['code' => 'uts', 'is_midterm_exam' => true]),
        (new GradingFactor)->setRawAttributes(['code' => 'uas', 'is_midterm_exam' => false]),
        (new GradingFactor)->setRawAttributes(['code' => 'tugas', 'is_midterm_exam' => false]),
    ];
}

/**
 * @return list<GradingFactor>
 */
function tahfizhFactors(): array
{
    return [
        (new GradingFactor)->setRawAttributes(['code' => 'target_hafalan', 'is_midterm_exam' => false]),
        (new GradingFactor)->setRawAttributes(['code' => 'uas_tahfizh', 'is_midterm_exam' => false]),
    ];
}

test('nothing is disabled while UTS is enabled and the student entered before the midterm', function () {
    $disabledCodes = (new MidtermExclusionRule)->disabledFactorCodesFor(
        midtermSemester(true, '2025-10-01'),
        studentEnteredOn('2025-07-01'),
        teoriKitabFactors(),
    );

    expect($disabledCodes)->toBe([]);
});

test('UTS is disabled for everyone when the semester switches UTS off', function () {
    $rule = new MidtermExclusionRule;
    $semester = midtermSemester(false, null);

    expect($rule->disabledFactorCodesFor($semester, studentEnteredOn('2025-07-01'), teoriKitabFactors()))->toBe(['uts']);
    expect($rule->disabledFactorCodesForSemester($semester, teoriKitabFactors()))->toBe(['uts']);
});

test('UTS is disabled for a student who entered after the midterm exam date', function () {
    $rule = new MidtermExclusionRule;
    $semester = midtermSemester(true, '2025-10-01');

    expect($rule->disabledFactorCodesFor($semester, studentEnteredOn('2025-10-02'), teoriKitabFactors()))->toBe(['uts']);
    // The semester-wide view is unaffected by a single student's entry date.
    expect($rule->disabledFactorCodesForSemester($semester, teoriKitabFactors()))->toBe([]);
});

test('a student who entered on the midterm exam date still sits the UTS', function () {
    expect((new MidtermExclusionRule)->disabledFactorCodesFor(
        midtermSemester(true, '2025-10-01'),
        studentEnteredOn('2025-10-01'),
        teoriKitabFactors(),
    ))->toBe([]);
});

test('without a midterm exam date the entry date never disables UTS', function () {
    expect((new MidtermExclusionRule)->disabledFactorCodesFor(
        midtermSemester(true, null),
        studentEnteredOn('2026-01-15'),
        teoriKitabFactors(),
    ))->toBe([]);
});

test('a student without an entry date is not excluded by the midterm date', function () {
    expect((new MidtermExclusionRule)->disabledFactorCodesFor(
        midtermSemester(true, '2025-10-01'),
        studentEnteredOn(null),
        teoriKitabFactors(),
    ))->toBe([]);
});

test('a template without a midterm factor (Tahfizh) is never touched', function () {
    $rule = new MidtermExclusionRule;

    expect($rule->disabledFactorCodesFor(midtermSemester(false, '2025-10-01'), studentEnteredOn('2025-11-01'), tahfizhFactors()))->toBe([]);
    expect($rule->disabledFactorCodesForSemester(midtermSemester(false, null), tahfizhFactors()))->toBe([]);
});
