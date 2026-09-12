<?php

use App\Models\ClassLevel;
use App\Models\School;
use App\Models\Student;
use Illuminate\Support\Facades\Log;

function runBackfillStudentsClassLevelIdMigration(): void
{
    (require database_path('migrations/2026_09_13_000000_backfill_students_class_level_id.php'))->up();
}

test('backfill resolves class_level_id from the matching class_level slug within the same school', function () {
    $school = School::factory()->create(['is_active' => true]);
    $classLevel = ClassLevel::factory()->create(['school_id' => $school->id, 'slug' => 'tamhidi']);
    $student = Student::factory()->create([
        'school_id' => $school->id,
        'class_level' => 'tamhidi',
        'class_level_id' => null,
    ]);

    runBackfillStudentsClassLevelIdMigration();

    expect($student->fresh()->class_level_id)->toBe($classLevel->id);
});

test('backfill only matches class levels belonging to the same school', function () {
    $school = School::factory()->create(['is_active' => true]);
    $otherSchool = School::factory()->create(['is_active' => false]);
    ClassLevel::factory()->create(['school_id' => $otherSchool->id, 'slug' => 'tamhidi']);
    $student = Student::factory()->create([
        'school_id' => $school->id,
        'class_level' => 'tamhidi',
        'class_level_id' => null,
    ]);

    Log::shouldReceive('warning')->once();

    runBackfillStudentsClassLevelIdMigration();

    expect($student->fresh()->class_level_id)->toBeNull();
});

test('backfill also fixes soft-deleted students so a restore works', function () {
    $school = School::factory()->create(['is_active' => true]);
    $classLevel = ClassLevel::factory()->create(['school_id' => $school->id, 'slug' => 'ibtida_1']);
    $student = Student::factory()->create([
        'school_id' => $school->id,
        'class_level' => 'ibtida_1',
        'class_level_id' => null,
    ]);
    $student->delete();

    runBackfillStudentsClassLevelIdMigration();

    expect(Student::withTrashed()->find($student->id)->class_level_id)->toBe($classLevel->id);
});

test('backfill for a student without school_id resolves only when exactly one active-school class level matches the slug', function () {
    $activeSchool = School::factory()->create(['is_active' => true]);
    $classLevel = ClassLevel::factory()->create(['school_id' => $activeSchool->id, 'slug' => 'tahfidz_1']);
    $student = Student::factory()->create([
        'school_id' => null,
        'class_level' => 'tahfidz_1',
        'class_level_id' => null,
    ]);

    runBackfillStudentsClassLevelIdMigration();

    expect($student->fresh()->class_level_id)->toBe($classLevel->id);
});

test('backfill leaves a school-less student unresolved and logs when the slug matches nothing', function () {
    $activeSchool = School::factory()->create(['is_active' => true]);
    ClassLevel::factory()->create(['school_id' => $activeSchool->id, 'slug' => 'tamhidi']);
    $student = Student::factory()->create([
        'school_id' => null,
        'class_level' => 'unknown_slug',
        'class_level_id' => null,
    ]);

    Log::shouldReceive('warning')->once();

    runBackfillStudentsClassLevelIdMigration();

    expect($student->fresh()->class_level_id)->toBeNull();
});

test('backfill leaves rows already resolved and rows without a class_level slug untouched', function () {
    $school = School::factory()->create(['is_active' => true]);
    $classLevelA = ClassLevel::factory()->create(['school_id' => $school->id, 'slug' => 'tamhidi']);
    $classLevelB = ClassLevel::factory()->create(['school_id' => $school->id, 'slug' => 'ibtida_1']);
    $alreadyResolved = Student::factory()->create([
        'school_id' => $school->id,
        'class_level' => 'tamhidi',
        'class_level_id' => $classLevelB->id,
    ]);
    $noSlug = Student::factory()->create([
        'school_id' => $school->id,
        'class_level' => null,
        'class_level_id' => null,
    ]);

    runBackfillStudentsClassLevelIdMigration();

    expect($alreadyResolved->fresh()->class_level_id)->toBe($classLevelB->id)
        ->and($noSlug->fresh()->class_level_id)->toBeNull();
});
