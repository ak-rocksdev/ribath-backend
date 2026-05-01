<?php

use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\School;
use App\Models\SubjectBook;
use App\Models\Teacher;
use App\Models\TeachingSchedule;
use App\Models\TimeSlot;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::firstOrCreate(['name' => 'view-schedules']);

    $this->school = School::factory()->create(['is_active' => true, 'name' => 'Pesantren Test']);
    $this->year = AcademicYear::factory()->create(['school_id' => $this->school->id, 'name' => '1447/1448']);
    $this->teacher = Teacher::factory()->create([
        'school_id' => $this->school->id,
        'full_name' => 'Abdul Kadir Hasan',
        'code' => 'AKH',
    ]);
});

function makeAuthorizedUser(): User
{
    $user = User::factory()->create();
    $role = Role::firstOrCreate(['name' => 'pengurus_pesantren']);
    $role->syncPermissions(['view-schedules']);
    $user->assignRole($role);
    return $user;
}

function seedScheduleFor(Teacher $teacher, School $school, AcademicYear $year, int $semester): TeachingSchedule
{
    $slot = TimeSlot::factory()->create(['school_id' => $school->id, 'sort_order' => 1]);
    $book = SubjectBook::factory()->create(['school_id' => $school->id]);
    $class = ClassLevel::factory()->create(['school_id' => $school->id]);

    return TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $year->id,
        'semester' => $semester,
        'day_of_week' => 'monday',
        'time_slot_id' => $slot->id,
        'subject_book_id' => $book->id,
        'class_level_id' => $class->id,
        'teacher_id' => $teacher->id,
        'is_active' => true,
    ]);
}

test('unauthenticated request returns 401', function () {
    $response = $this->getJson("/api/v1/teaching-schedules/export/teacher/{$this->teacher->id}?academic_year_id={$this->year->id}&semester=1");
    $response->assertUnauthorized();
});

test('user without view-schedules permission returns 403', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->getJson("/api/v1/teaching-schedules/export/teacher/{$this->teacher->id}?academic_year_id={$this->year->id}&semester=1");

    $response->assertForbidden();
});

test('missing academic_year_id returns 422', function () {
    $user = makeAuthorizedUser();

    $response = $this->actingAs($user)
        ->getJson("/api/v1/teaching-schedules/export/teacher/{$this->teacher->id}?semester=1");

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['academic_year_id']);
});

test('invalid semester returns 422', function () {
    $user = makeAuthorizedUser();

    $response = $this->actingAs($user)
        ->getJson("/api/v1/teaching-schedules/export/teacher/{$this->teacher->id}?academic_year_id={$this->year->id}&semester=9");

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['semester']);
});

test('non-existent teacher returns 404', function () {
    $user = makeAuthorizedUser();
    $missingUuid = '00000000-0000-0000-0000-000000000000';

    $response = $this->actingAs($user)
        ->getJson("/api/v1/teaching-schedules/export/teacher/{$missingUuid}?academic_year_id={$this->year->id}&semester=1");

    $response->assertNotFound();
});

test('successful landscape export returns inline PDF with code-based filename', function () {
    $user = makeAuthorizedUser();
    seedScheduleFor($this->teacher, $this->school, $this->year, 1);

    $response = $this->actingAs($user)
        ->get("/api/v1/teaching-schedules/export/teacher/{$this->teacher->id}?academic_year_id={$this->year->id}&semester=1&orientation=landscape");

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain('inline');
    expect($response->headers->get('Content-Disposition'))->toContain('Jadwal-AKH-Sem1-1447-1448.pdf');
});

test('successful portrait export returns inline PDF', function () {
    $user = makeAuthorizedUser();
    seedScheduleFor($this->teacher, $this->school, $this->year, 1);

    $response = $this->actingAs($user)
        ->get("/api/v1/teaching-schedules/export/teacher/{$this->teacher->id}?academic_year_id={$this->year->id}&semester=1&orientation=portrait");

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
});

test('teacher with zero schedules returns 200 with empty-state PDF', function () {
    $user = makeAuthorizedUser();
    // No schedule seeded — empty state path

    $response = $this->actingAs($user)
        ->get("/api/v1/teaching-schedules/export/teacher/{$this->teacher->id}?academic_year_id={$this->year->id}&semester=1");

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
});
