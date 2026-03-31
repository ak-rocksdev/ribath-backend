<?php

use App\Models\Student;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(\Database\Seeders\SchoolSeeder::class);
    $this->seed(\Database\Seeders\ClassLevelSeeder::class);
    $this->seed(\Database\Seeders\RolePermissionSeeder::class);
});

function createStudentAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    return $user;
}

function createPengurusPesantren(): User
{
    $user = User::factory()->create();
    $user->assignRole('pengurus_pesantren');

    return $user;
}

// Auth & Authorization
test('unauthenticated user cannot access students', function () {
    $this->getJson('/api/v1/students')
        ->assertStatus(401);
});

test('user without permission cannot access students', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/students')
        ->assertStatus(403);
});

test('super_admin can access students', function () {
    $admin = createStudentAdmin();

    $this->actingAs($admin)
        ->getJson('/api/v1/students')
        ->assertStatus(200)
        ->assertJsonPath('success', true);
});

test('pengurus_pesantren can access students', function () {
    $pengurus = createPengurusPesantren();

    $this->actingAs($pengurus)
        ->getJson('/api/v1/students')
        ->assertStatus(200);
});

// List Students
test('list students returns paginated results', function () {
    $admin = createStudentAdmin();
    Student::factory()->count(20)->create();

    $response = $this->actingAs($admin)
        ->getJson('/api/v1/students')
        ->assertStatus(200)
        ->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);

    expect($response->json('meta.total'))->toBe(20);
});

test('list students can search by full_name', function () {
    $admin = createStudentAdmin();
    Student::factory()->create(['full_name' => 'Ahmad Unique Name']);
    Student::factory()->count(5)->create();

    $response = $this->actingAs($admin)
        ->getJson('/api/v1/students?search=Ahmad Unique')
        ->assertStatus(200);

    expect($response->json('meta.total'))->toBe(1);
});

test('list students can filter by status', function () {
    $admin = createStudentAdmin();
    Student::factory()->count(3)->create(['status' => 'active']);
    Student::factory()->count(2)->graduated()->create();

    $response = $this->actingAs($admin)
        ->getJson('/api/v1/students?status=graduated')
        ->assertStatus(200);

    expect($response->json('meta.total'))->toBe(2);
});

test('list students can filter by class_level', function () {
    $admin = createStudentAdmin();
    Student::factory()->create(['class_level' => 'tamhidi']);
    Student::factory()->create(['class_level' => 'ibtida_1']);
    Student::factory()->create(['class_level' => 'tamhidi']);

    $response = $this->actingAs($admin)
        ->getJson('/api/v1/students?class_level=tamhidi')
        ->assertStatus(200);

    expect($response->json('meta.total'))->toBe(2);
});

test('list students can filter by program', function () {
    $admin = createStudentAdmin();
    Student::factory()->count(3)->create(['program' => 'tahfidz']);
    Student::factory()->count(2)->create(['program' => 'regular']);

    $response = $this->actingAs($admin)
        ->getJson('/api/v1/students?program=tahfidz')
        ->assertStatus(200);

    expect($response->json('meta.total'))->toBe(3);
});

// Create Student
test('create student manually', function () {
    $admin = createStudentAdmin();

    $this->actingAs($admin)
        ->postJson('/api/v1/students', [
            'full_name' => 'New Student',
            'birth_date' => '2010-05-15',
            'gender' => 'L',
            'program' => 'tahfidz',
            'entry_date' => '2026-01-15',
            'class_level' => 'tamhidi',
            'address' => 'Jl. Test No. 123',
        ])
        ->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.full_name', 'New Student')
        ->assertJsonPath('data.class_level', 'tamhidi');
});

test('create student with guardian link', function () {
    $admin = createStudentAdmin();
    $guardian = User::factory()->create();

    $this->actingAs($admin)
        ->postJson('/api/v1/students', [
            'full_name' => 'Student With Guardian',
            'birth_date' => '2010-05-15',
            'gender' => 'P',
            'program' => 'regular',
            'entry_date' => '2026-01-15',
            'guardian_user_id' => $guardian->id,
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.guardian_user_id', $guardian->id);
});

test('create student validates required fields', function () {
    $admin = createStudentAdmin();

    $this->actingAs($admin)
        ->postJson('/api/v1/students', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['full_name', 'birth_date', 'gender', 'program', 'entry_date']);
});

test('create student validates gender values', function () {
    $admin = createStudentAdmin();

    $this->actingAs($admin)
        ->postJson('/api/v1/students', [
            'full_name' => 'Test',
            'birth_date' => '2010-05-15',
            'gender' => 'X',
            'program' => 'tahfidz',
            'entry_date' => '2026-01-15',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['gender']);
});

test('create student validates class_level values', function () {
    $admin = createStudentAdmin();

    $this->actingAs($admin)
        ->postJson('/api/v1/students', [
            'full_name' => 'Test',
            'birth_date' => '2010-05-15',
            'gender' => 'L',
            'program' => 'tahfidz',
            'entry_date' => '2026-01-15',
            'class_level' => 'invalid_level',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['class_level']);
});

// Show Student
test('show student with relations', function () {
    $admin = createStudentAdmin();
    $guardian = User::factory()->create();
    $student = Student::factory()->create(['guardian_user_id' => $guardian->id]);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/students/{$student->id}")
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $student->id);

    expect($response->json('data.guardian'))->not->toBeNull();
});

test('show student includes all profile relationships', function () {
    $admin = createStudentAdmin();
    $student = Student::factory()->create();

    $student->parents()->create(['relation' => 'father', 'name' => 'Ahmad', 'phone' => '08111']);
    $student->parents()->create(['relation' => 'mother', 'name' => 'Fatimah', 'phone' => '08222']);
    $student->health()->create(['blood_type' => 'O', 'allergies' => 'Kacang']);
    $student->educationHistory()->create(['last_school_name' => 'SD 1', 'last_education_level' => 'elementary']);
    $student->religiousProfile()->create(['quran_reading_ability' => 'fluent', 'memorized_juz' => 5]);
    $student->additionalInfo()->create(['hobbies_talents' => 'Membaca']);
    $student->documents()->create([
        'document_type' => 'foto',
        'file_path' => 'student-documents/test/foto.jpg',
        'original_filename' => 'foto.jpg',
        'file_size' => 1000,
    ]);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/students/{$student->id}");

    $response->assertOk()
        ->assertJsonStructure(['data' => [
            'parents',
            'health',
            'education_history',
            'religious_profile',
            'additional_info',
            'documents',
        ]])
        ->assertJsonPath('data.parents.0.relation', 'father')
        ->assertJsonPath('data.health.blood_type', 'O')
        ->assertJsonPath('data.education_history.last_school_name', 'SD 1')
        ->assertJsonPath('data.religious_profile.memorized_juz', 5)
        ->assertJsonPath('data.additional_info.hobbies_talents', 'Membaca')
        ->assertJsonPath('data.documents.0.document_type', 'foto');
});

// Update Student
test('update student', function () {
    $admin = createStudentAdmin();
    $student = Student::factory()->create();

    $this->actingAs($admin)
        ->putJson("/api/v1/students/{$student->id}", [
            'full_name' => 'Updated Student Name',
            'class_level' => 'ibtida_1',
            'address' => 'New Address',
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.full_name', 'Updated Student Name')
        ->assertJsonPath('data.class_level', 'ibtida_1')
        ->assertJsonPath('data.address', 'New Address');
});

test('update student with nested relations', function () {
    $admin = createStudentAdmin();
    $student = Student::factory()->create();

    $response = $this->actingAs($admin)
        ->putJson("/api/v1/students/{$student->id}", [
            'nik' => '1234567890123456',
            'parents' => [
                'father' => ['name' => 'Ahmad', 'phone' => '08111111111', 'occupation' => 'Guru'],
                'mother' => ['name' => 'Fatimah', 'phone' => '08222222222'],
            ],
            'health' => ['blood_type' => 'AB', 'allergies' => 'Debu'],
            'education_history' => ['last_school_name' => 'SD 2', 'last_education_level' => 'elementary'],
            'religious_profile' => ['quran_reading_ability' => 'fluent', 'memorized_juz' => 10],
            'additional_info' => ['hobbies_talents' => 'Kaligrafi'],
        ]);

    $response->assertOk()
        ->assertJsonPath('data.nik', '1234567890123456')
        ->assertJsonPath('data.health.blood_type', 'AB')
        ->assertJsonPath('data.education_history.last_school_name', 'SD 2')
        ->assertJsonPath('data.religious_profile.memorized_juz', 10)
        ->assertJsonPath('data.additional_info.hobbies_talents', 'Kaligrafi');

    $this->assertDatabaseHas('student_parents', [
        'student_id' => $student->id,
        'relation' => 'father',
        'name' => 'Ahmad',
        'occupation' => 'Guru',
    ]);
    $this->assertDatabaseHas('student_parents', [
        'student_id' => $student->id,
        'relation' => 'mother',
        'name' => 'Fatimah',
    ]);
});

test('update nested relations upserts existing data', function () {
    $admin = createStudentAdmin();
    $student = Student::factory()->create();

    // Create initial data
    $student->parents()->create(['relation' => 'father', 'name' => 'Old Name', 'phone' => '08111']);
    $student->health()->create(['blood_type' => 'A']);

    // Update with new data
    $response = $this->actingAs($admin)
        ->putJson("/api/v1/students/{$student->id}", [
            'parents' => [
                'father' => ['name' => 'New Name', 'phone' => '08999'],
            ],
            'health' => ['blood_type' => 'B', 'allergies' => 'Kacang'],
        ]);

    $response->assertOk();

    // Should update existing, not create duplicates
    expect($student->parents()->where('relation', 'father')->count())->toBe(1);
    $this->assertDatabaseHas('student_parents', [
        'student_id' => $student->id,
        'relation' => 'father',
        'name' => 'New Name',
        'phone' => '08999',
    ]);
    $this->assertDatabaseHas('student_health', [
        'student_id' => $student->id,
        'blood_type' => 'B',
        'allergies' => 'Kacang',
    ]);
});

// Delete Student
test('soft delete student', function () {
    $admin = createStudentAdmin();
    $student = Student::factory()->create();

    $this->actingAs($admin)
        ->deleteJson("/api/v1/students/{$student->id}")
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    expect(Student::find($student->id))->toBeNull();
    expect(Student::withTrashed()->find($student->id))->not->toBeNull();
});

// Status Updates
test('update student status to graduated', function () {
    $admin = createStudentAdmin();
    $student = Student::factory()->create(['status' => 'active']);

    $this->actingAs($admin)
        ->patchJson("/api/v1/students/{$student->id}/status", [
            'status' => 'graduated',
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'graduated');
});

test('update student status to transferred', function () {
    $admin = createStudentAdmin();
    $student = Student::factory()->create(['status' => 'active']);

    $this->actingAs($admin)
        ->patchJson("/api/v1/students/{$student->id}/status", [
            'status' => 'transferred',
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'transferred');
});

test('update student status to withdrawn', function () {
    $admin = createStudentAdmin();
    $student = Student::factory()->create(['status' => 'active']);

    $this->actingAs($admin)
        ->patchJson("/api/v1/students/{$student->id}/status", [
            'status' => 'withdrawn',
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'withdrawn');
});

test('invalid status is rejected', function () {
    $admin = createStudentAdmin();
    $student = Student::factory()->create();

    $this->actingAs($admin)
        ->patchJson("/api/v1/students/{$student->id}/status", [
            'status' => 'invalid_status',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});
