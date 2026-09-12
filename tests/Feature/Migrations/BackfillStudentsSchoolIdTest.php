<?php

use App\Models\School;
use App\Models\Student;

function runBackfillStudentsSchoolIdMigration(): void
{
    (require database_path('migrations/2026_09_12_000000_backfill_students_school_id.php'))->up();
}

test('backfill assigns the active school to students without school_id', function () {
    $activeSchool = School::factory()->create(['is_active' => true]);
    $inactiveSchool = School::factory()->create(['is_active' => false]);
    $studentWithoutSchool = Student::factory()->create(['school_id' => null]);
    $studentOfInactiveSchool = Student::factory()->create(['school_id' => $inactiveSchool->id]);

    runBackfillStudentsSchoolIdMigration();

    expect($studentWithoutSchool->fresh()->school_id)->toBe($activeSchool->id)
        ->and($studentOfInactiveSchool->fresh()->school_id)->toBe($inactiveSchool->id);
});

test('backfill also fixes soft-deleted students so a restore works', function () {
    $activeSchool = School::factory()->create(['is_active' => true]);
    $softDeletedStudent = Student::factory()->create(['school_id' => null]);
    $softDeletedStudent->delete();

    runBackfillStudentsSchoolIdMigration();

    expect(Student::withTrashed()->find($softDeletedStudent->id)->school_id)->toBe($activeSchool->id);
});

test('backfill leaves rows untouched when there is not exactly one active school', function () {
    School::factory()->count(2)->create(['is_active' => true]);
    $studentWithoutSchool = Student::factory()->create(['school_id' => null]);

    runBackfillStudentsSchoolIdMigration();

    expect($studentWithoutSchool->fresh()->school_id)->toBeNull();
});
