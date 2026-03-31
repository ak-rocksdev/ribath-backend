<?php

use App\Models\Registration;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->school = School::factory()->create(['is_active' => true]);

    $this->registration = Registration::factory()->create([
        'school_id' => $this->school->id,
        'status' => 'accepted',
    ]);

    $this->student = Student::factory()->create([
        'school_id' => $this->school->id,
        'registration_id' => $this->registration->id,
        'profile_completion_status' => 'incomplete',
    ]);
});

// --- GET endpoint tests ---

test('show returns student data with all relationships', function () {
    $response = $this->getJson("/api/v1/public/student-completion/{$this->registration->id}");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.student.id', $this->student->id)
        ->assertJsonPath('data.student.full_name', $this->student->full_name)
        ->assertJsonPath('data.student.profile_completion_status', 'incomplete')
        ->assertJsonStructure([
            'data' => [
                'student',
                'parents',
                'health',
                'education_history',
                'religious_profile',
                'additional_info',
                'documents',
            ],
        ]);
});

test('show returns 404 for non-existent registration', function () {
    $fakeId = '00000000-0000-0000-0000-000000000000';
    $response = $this->getJson("/api/v1/public/student-completion/{$fakeId}");

    $response->assertNotFound();
});

test('show returns 400 for non-accepted registration', function () {
    $this->registration->update(['status' => 'new']);

    $response = $this->getJson("/api/v1/public/student-completion/{$this->registration->id}");

    $response->assertStatus(400);
});

// --- PUT endpoint tests ---

test('save as draft without required fields succeeds', function () {
    $response = $this->putJson("/api/v1/public/student-completion/{$this->registration->id}", [
        'status' => 'draft',
        'student' => [
            'nik' => '1234567890123456',
            'phone' => '08123456789',
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.profile_completion_status', 'draft');

    $this->assertDatabaseHas('students', [
        'id' => $this->student->id,
        'nik' => '1234567890123456',
        'profile_completion_status' => 'draft',
    ]);
});

test('save as draft upserts parents', function () {
    $response = $this->putJson("/api/v1/public/student-completion/{$this->registration->id}", [
        'status' => 'draft',
        'parents' => [
            'father' => ['name' => 'Ahmad', 'phone' => '08111111111'],
            'mother' => ['name' => 'Fatimah', 'phone' => '08222222222'],
        ],
    ]);

    $response->assertOk();

    $this->assertDatabaseHas('student_parents', [
        'student_id' => $this->student->id,
        'relation' => 'father',
        'name' => 'Ahmad',
    ]);

    $this->assertDatabaseHas('student_parents', [
        'student_id' => $this->student->id,
        'relation' => 'mother',
        'name' => 'Fatimah',
    ]);
});

test('save as draft upserts health, education, religious, additional', function () {
    $response = $this->putJson("/api/v1/public/student-completion/{$this->registration->id}", [
        'status' => 'draft',
        'health' => ['blood_type' => 'O', 'allergies' => 'Kacang'],
        'education_history' => ['last_school_name' => 'SD Negeri 1', 'last_education_level' => 'elementary'],
        'religious_profile' => ['quran_reading_ability' => 'fluent', 'memorized_juz' => 3],
        'additional_info' => ['hobbies_talents' => 'Membaca'],
    ]);

    $response->assertOk();

    $this->assertDatabaseHas('student_health', [
        'student_id' => $this->student->id,
        'blood_type' => 'O',
    ]);
    $this->assertDatabaseHas('student_education_history', [
        'student_id' => $this->student->id,
        'last_school_name' => 'SD Negeri 1',
    ]);
    $this->assertDatabaseHas('student_religious_profile', [
        'student_id' => $this->student->id,
        'memorized_juz' => 3,
    ]);
    $this->assertDatabaseHas('student_additional_info', [
        'student_id' => $this->student->id,
        'hobbies_talents' => 'Membaca',
    ]);
});

test('complete fails without required fields', function () {
    $response = $this->putJson("/api/v1/public/student-completion/{$this->registration->id}", [
        'status' => 'completed',
        'student' => ['email' => 'test@test.com'],
    ]);

    $response->assertUnprocessable();
});

test('complete fails without foto document', function () {
    $response = $this->putJson("/api/v1/public/student-completion/{$this->registration->id}", [
        'status' => 'completed',
        'student' => ['nik' => '1234567890123456', 'gender' => 'L', 'address' => 'Jl. Test No 1'],
        'parents' => [
            'father' => ['name' => 'Ahmad', 'phone' => '08111111111'],
            'mother' => ['name' => 'Fatimah', 'phone' => '08222222222'],
        ],
        'health' => ['blood_type' => 'O'],
        'education_history' => ['last_school_name' => 'SD 1', 'last_education_level' => 'elementary'],
        'religious_profile' => ['quran_reading_ability' => 'fluent'],
        'agreements' => ['agreed_to_rules' => true, 'agreed_to_commitment' => true, 'data_verified' => true],
    ]);

    $response->assertUnprocessable()
        ->assertJsonFragment(['message' => 'Foto santri wajib diunggah sebelum mengirim data']);
});

test('complete succeeds with all required fields and foto', function () {
    // Upload foto first
    Storage::fake('public');
    $this->student->documents()->create([
        'document_type' => 'foto',
        'file_path' => 'student-documents/test/foto.jpg',
        'original_filename' => 'foto.jpg',
        'file_size' => 1000,
    ]);

    $response = $this->putJson("/api/v1/public/student-completion/{$this->registration->id}", [
        'status' => 'completed',
        'student' => ['nik' => '1234567890123456', 'gender' => 'L', 'address' => 'Jl. Test No 1'],
        'parents' => [
            'father' => ['name' => 'Ahmad', 'phone' => '08111111111'],
            'mother' => ['name' => 'Fatimah', 'phone' => '08222222222'],
        ],
        'health' => ['blood_type' => 'O'],
        'education_history' => ['last_school_name' => 'SD 1', 'last_education_level' => 'elementary'],
        'religious_profile' => ['quran_reading_ability' => 'fluent'],
        'agreements' => ['agreed_to_rules' => true, 'agreed_to_commitment' => true, 'data_verified' => true],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.profile_completion_status', 'completed');

    $this->student->refresh();
    expect($this->student->profile_completion_status)->toBe('completed');
    expect($this->student->profile_completed_at)->not->toBeNull();
    expect($this->student->agreed_to_rules_at)->not->toBeNull();
});

test('update returns 403 when already completed', function () {
    $this->student->update(['profile_completion_status' => 'completed']);

    $response = $this->putJson("/api/v1/public/student-completion/{$this->registration->id}", [
        'status' => 'draft',
        'student' => ['nik' => '9999999999999999'],
    ]);

    $response->assertForbidden();
});

// --- Document upload tests ---

test('upload document succeeds', function () {
    Storage::fake('public');

    $file = UploadedFile::fake()->image('foto.jpg', 400, 400)->size(500);

    $response = $this->postJson(
        "/api/v1/public/student-completion/{$this->registration->id}/documents",
        ['document_type' => 'foto', 'file' => $file],
    );

    $response->assertOk()
        ->assertJsonPath('data.document_type', 'foto');

    $this->assertDatabaseHas('student_documents', [
        'student_id' => $this->student->id,
        'document_type' => 'foto',
    ]);
});

test('upload replaces existing document of same type', function () {
    Storage::fake('public');

    // Upload first
    $file1 = UploadedFile::fake()->image('foto1.jpg')->size(100);
    $this->postJson(
        "/api/v1/public/student-completion/{$this->registration->id}/documents",
        ['document_type' => 'foto', 'file' => $file1],
    );

    // Upload replacement
    $file2 = UploadedFile::fake()->image('foto2.jpg')->size(200);
    $this->postJson(
        "/api/v1/public/student-completion/{$this->registration->id}/documents",
        ['document_type' => 'foto', 'file' => $file2],
    );

    // Should only have one foto document
    expect($this->student->documents()->where('document_type', 'foto')->count())->toBe(1);
});

test('upload rejects invalid document type', function () {
    Storage::fake('public');

    $file = UploadedFile::fake()->image('test.jpg');

    $response = $this->postJson(
        "/api/v1/public/student-completion/{$this->registration->id}/documents",
        ['document_type' => 'invalid_type', 'file' => $file],
    );

    $response->assertUnprocessable();
});

test('upload rejects file over 5MB', function () {
    Storage::fake('public');

    $file = UploadedFile::fake()->image('big.jpg')->size(6000);

    $response = $this->postJson(
        "/api/v1/public/student-completion/{$this->registration->id}/documents",
        ['document_type' => 'foto', 'file' => $file],
    );

    $response->assertUnprocessable();
});

test('upload document returns 403 when completed', function () {
    Storage::fake('public');
    $this->student->update(['profile_completion_status' => 'completed']);

    $file = UploadedFile::fake()->image('foto.jpg');

    $response = $this->postJson(
        "/api/v1/public/student-completion/{$this->registration->id}/documents",
        ['document_type' => 'foto', 'file' => $file],
    );

    $response->assertForbidden();
});

// --- Document delete tests ---

test('delete document succeeds', function () {
    Storage::fake('public');

    $this->student->documents()->create([
        'document_type' => 'foto',
        'file_path' => 'student-documents/test/foto.jpg',
        'original_filename' => 'foto.jpg',
        'file_size' => 1000,
    ]);

    $response = $this->deleteJson(
        "/api/v1/public/student-completion/{$this->registration->id}/documents/foto",
    );

    $response->assertOk();
    expect($this->student->documents()->count())->toBe(0);
});

test('delete document returns 403 when completed', function () {
    $this->student->update(['profile_completion_status' => 'completed']);

    $response = $this->deleteJson(
        "/api/v1/public/student-completion/{$this->registration->id}/documents/foto",
    );

    $response->assertForbidden();
});
