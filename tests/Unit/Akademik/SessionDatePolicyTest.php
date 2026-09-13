<?php

use App\Models\AcademicSemester;
use App\Models\TeachingSchedule;
use App\Services\Akademik\SessionDatePolicy;
use Illuminate\Support\Carbon;

/*
 * Pure: models are built with raw attributes and "today" is frozen with
 * Carbon::setTestNow — Wednesday 2025-09-10. The schedule meets on Mondays.
 */

beforeEach(function () {
    Carbon::setTestNow('2025-09-10 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function sessionPolicyMondaySchedule(): TeachingSchedule
{
    return (new TeachingSchedule)->setRawAttributes(['day_of_week' => 'monday']);
}

function sessionPolicySemester(?string $startDate, ?string $endDate): AcademicSemester
{
    return (new AcademicSemester)->setRawAttributes(['start_date' => $startDate, 'end_date' => $endDate]);
}

function sessionPolicyViolation(string $sessionDate, bool $actorIsSuperAdmin, bool $isEditingExistingSession, ?AcademicSemester $semester = null): ?string
{
    return (new SessionDatePolicy)->violationFor(
        sessionPolicyMondaySchedule(),
        Carbon::parse($sessionDate),
        $semester ?? sessionPolicySemester('2025-07-01', '2025-12-31'),
        $actorIsSuperAdmin,
        $isEditingExistingSession,
    );
}

test('the edit window is 14 days', function () {
    expect(SessionDatePolicy::ATTENDANCE_EDIT_WINDOW_DAYS)->toBe(14);
});

test('a date on another weekday is rejected for everyone', function () {
    expect(sessionPolicyViolation('2025-09-09', false, false))->toBe(SessionDatePolicy::MESSAGE_WEEKDAY_MISMATCH);
    expect(sessionPolicyViolation('2025-09-09', true, false))->toBe(SessionDatePolicy::MESSAGE_WEEKDAY_MISMATCH);
});

test('a past date on the schedule weekday is allowed', function () {
    expect(sessionPolicyViolation('2025-09-08', false, false))->toBeNull();
    expect(sessionPolicyViolation('2025-09-08', false, true))->toBeNull();
});

test('a non-super_admin cannot use a future date, a super_admin can', function () {
    expect(sessionPolicyViolation('2025-09-15', false, false))->toBe(SessionDatePolicy::MESSAGE_FUTURE_DATE);
    expect(sessionPolicyViolation('2025-09-15', true, false))->toBeNull();
});

test('editing is limited to 14 days back for a non-super_admin only', function () {
    // today − 14 = 2025-08-27; 2025-08-25 is outside, 2025-09-01 inside.
    expect(sessionPolicyViolation('2025-08-25', false, true))->toBe(SessionDatePolicy::MESSAGE_EDIT_WINDOW);
    expect(sessionPolicyViolation('2025-09-01', false, true))->toBeNull();
    expect(sessionPolicyViolation('2025-08-25', true, true))->toBeNull();
    // Recording a missed (new) session is not an edit.
    expect(sessionPolicyViolation('2025-08-25', false, false))->toBeNull();
});

test('the semester range bounds everyone when its dates are set', function () {
    expect(sessionPolicyViolation('2025-06-30', true, false))->toBe(SessionDatePolicy::MESSAGE_OUTSIDE_SEMESTER);
    expect(sessionPolicyViolation('2026-01-05', true, false))->toBe(SessionDatePolicy::MESSAGE_OUTSIDE_SEMESTER);
    expect(sessionPolicyViolation('2025-06-30', true, false, sessionPolicySemester(null, null)))->toBeNull();
    expect(sessionPolicyViolation('2026-01-05', true, false, sessionPolicySemester('2025-07-01', null)))->toBeNull();
});

test('the override warning is raised only for a super_admin beyond the normal rules', function () {
    $policy = new SessionDatePolicy;

    expect($policy->requiresOverrideWarning(Carbon::parse('2025-09-15'), true, false))->toBeTrue();
    expect($policy->requiresOverrideWarning(Carbon::parse('2025-09-10'), true, false))->toBeFalse();
    expect($policy->requiresOverrideWarning(Carbon::parse('2025-08-25'), true, true))->toBeTrue();
    expect($policy->requiresOverrideWarning(Carbon::parse('2025-08-25'), true, false))->toBeFalse();
    expect($policy->requiresOverrideWarning(Carbon::parse('2025-09-01'), true, true))->toBeFalse();
    expect($policy->requiresOverrideWarning(Carbon::parse('2025-09-15'), false, false))->toBeFalse();
});

test('editing attendances checks only the actor limits, not the weekday or semester', function () {
    $policy = new SessionDatePolicy;

    // A Tuesday outside the semester is fine to edit when recent: it was checked when recorded.
    expect($policy->violationForAttendanceEdit(Carbon::parse('2025-09-09'), false))->toBeNull();
    expect($policy->violationForAttendanceEdit(Carbon::parse('2025-08-25'), false))->toBe(SessionDatePolicy::MESSAGE_EDIT_WINDOW);
    expect($policy->violationForAttendanceEdit(Carbon::parse('2025-09-15'), false))->toBe(SessionDatePolicy::MESSAGE_FUTURE_DATE);
    expect($policy->violationForAttendanceEdit(Carbon::parse('2025-08-25'), true))->toBeNull();
    expect($policy->violationForAttendanceEdit(Carbon::parse('2025-09-15'), true))->toBeNull();
});

test('day 14 back is the last editable day and day 15 is rejected', function () {
    $policy = new SessionDatePolicy;

    expect($policy->violationForAttendanceEdit(Carbon::parse('2025-08-27'), false))->toBeNull();
    expect($policy->violationForAttendanceEdit(Carbon::parse('2025-08-26'), false))->toBe(SessionDatePolicy::MESSAGE_EDIT_WINDOW);
});

test('libur massal range-cancel allows any future date for everyone, but bounds a non-super_admin to the edit window in the past', function () {
    $policy = new SessionDatePolicy;

    // Future dates: fine for everyone (a planning action, unlike recording).
    expect($policy->isPastEditWindowForRangeCancel(Carbon::parse('2025-09-15'), false))->toBeFalse();
    expect($policy->isPastEditWindowForRangeCancel(Carbon::parse('2025-09-15'), true))->toBeFalse();
    // Today and inside the 14-day window: fine for a non-super_admin.
    expect($policy->isPastEditWindowForRangeCancel(Carbon::parse('2025-09-10'), false))->toBeFalse();
    expect($policy->isPastEditWindowForRangeCancel(Carbon::parse('2025-08-27'), false))->toBeFalse();
    // Outside the window in the past: blocked for a non-super_admin, fine for a super_admin.
    expect($policy->isPastEditWindowForRangeCancel(Carbon::parse('2025-08-26'), false))->toBeTrue();
    expect($policy->isPastEditWindowForRangeCancel(Carbon::parse('2025-08-26'), true))->toBeFalse();
});

test('today is the WIB (Asia/Jakarta) date, not the UTC one', function () {
    // 2025-09-09 23:30 UTC = Wednesday 2025-09-10 06:30 WIB.
    Carbon::setTestNow(Carbon::parse('2025-09-09 23:30:00', 'UTC'));
    $policy = new SessionDatePolicy;

    expect($policy->isFutureDate(Carbon::parse('2025-09-10')))->toBeFalse();
    expect($policy->isFutureDate(Carbon::parse('2025-09-11')))->toBeTrue();
    expect($policy->isInsideEditWindow(Carbon::parse('2025-08-27')))->toBeTrue();
    expect($policy->isInsideEditWindow(Carbon::parse('2025-08-26')))->toBeFalse();
    expect($policy->requiresOverrideWarning(Carbon::parse('2025-09-10'), true, false))->toBeFalse();
});
