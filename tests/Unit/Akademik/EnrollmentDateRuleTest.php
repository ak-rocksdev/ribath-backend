<?php

use App\Models\Student;
use App\Services\Akademik\Calculation\EnrollmentDateRule;
use Illuminate\Support\Carbon;

/*
 * Models are built with raw attributes so no database connection (or app
 * container) is needed: the rule only reads attributes, it never queries.
 */

function enrollmentRuleStudent(?string $entryDate): Student
{
    return (new Student)->setRawAttributes(['entry_date' => $entryDate]);
}

test('a student who entered before the date is expected', function () {
    expect((new EnrollmentDateRule)->isExpectedOn(enrollmentRuleStudent('2025-07-01'), Carbon::parse('2025-09-08')))->toBeTrue();
});

test('a student who entered on the date itself is expected', function () {
    expect((new EnrollmentDateRule)->isExpectedOn(enrollmentRuleStudent('2025-09-08'), Carbon::parse('2025-09-08')))->toBeTrue();
});

test('a student who entered after the date is not expected', function () {
    expect((new EnrollmentDateRule)->isExpectedOn(enrollmentRuleStudent('2025-09-09'), Carbon::parse('2025-09-08')))->toBeFalse();
});

test('a student without an entry_date is always expected', function () {
    expect((new EnrollmentDateRule)->isExpectedOn(enrollmentRuleStudent(null), Carbon::parse('2000-01-01')))->toBeTrue();
});
