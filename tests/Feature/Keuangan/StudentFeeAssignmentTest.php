<?php

use App\Models\AcademicYear;
use App\Models\CashBookCategory;
use App\Models\ClassLevel;
use App\Models\FeeActivityLog;
use App\Models\FeeSchedule;
use App\Models\FeeType;
use App\Models\Registration;
use App\Models\RegistrationPeriod;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentFeeAssignment;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    foreach (['view-registrations', 'manage-registrations', 'manage-student-fees', 'view-student-fees'] as $perm) {
        Permission::firstOrCreate(['name' => $perm]);
    }

    $this->school = School::factory()->create(['is_active' => true]);
    $this->activeAy = AcademicYear::factory()->create(['school_id' => $this->school->id, 'is_active' => true]);
    ClassLevel::factory()->create(['slug' => 'ibtida_1']);
    $this->category = CashBookCategory::factory()->for($this->school, 'school')->create();
    $this->spp = FeeType::factory()->forSchool($this->school)->withCadence('monthly')
        ->create(['code' => 'spp', 'label' => 'SPP', 'cash_book_category_id' => $this->category->id]);
    $this->daftar = FeeType::factory()->forSchool($this->school)->withCadence('once_at_enrollment')
        ->create(['code' => 'pendaftaran', 'label' => 'Pendaftaran', 'cash_book_category_id' => $this->category->id]);

    FeeSchedule::factory()->forSchool($this->school)->forAcademicYear($this->activeAy)->forFeeType($this->spp)
        ->create(['amount' => 500000]);
    FeeSchedule::factory()->forSchool($this->school)->forAcademicYear($this->activeAy)->forFeeType($this->daftar)
        ->create(['amount' => 5000000]);
});

function makeStudentFeeManager(): User
{
    $user = User::factory()->create();
    $role = Role::firstOrCreate(['name' => 'pengurus_pesantren']);
    $role->syncPermissions(['view-registrations', 'manage-registrations', 'manage-student-fees', 'view-student-fees']);
    $user->assignRole($role);

    return $user;
}

function makeUnprivilegedFeeUser(): User
{
    $user = User::factory()->create();
    $role = Role::firstOrCreate(['name' => 'no_fee_access']);
    $role->syncPermissions([]);
    $user->assignRole($role);

    return $user;
}

// ──────────────────────────────────────────────────────
// Auto-snapshot via PSB approval (FR-009)
// ──────────────────────────────────────────────────────

test('PSB approval auto-snapshots all active fee_types with schedule', function () {
    $period = RegistrationPeriod::factory()->forSchool($this->school)->forAcademicYear($this->activeAy)->create();
    $reg = Registration::factory()->create([
        'school_id' => $this->school->id,
        'registration_period_id' => $period->id,
        'status' => Registration::STATUS_NEW,
    ]);
    $admin = makeStudentFeeManager();

    $this->actingAs($admin)
        ->postJson("/api/v1/psb/registrations/{$reg->id}/accept", ['class_level' => 'ibtida_1'])
        ->assertOk();

    $student = Student::where('registration_id', $reg->id)->first();
    expect($student)->not->toBeNull();
    expect($student->feeAssignments()->count())->toBe(2);

    $sppAssignment = $student->feeAssignments()->where('fee_type_id', $this->spp->id)->first();
    expect((int) $sppAssignment->locked_amount)->toBe(500000)
        ->and($sppAssignment->cadence)->toBe('monthly')
        ->and($sppAssignment->source)->toBe(StudentFeeAssignment::SOURCE_AUTO_ENROLLMENT)
        ->and($sppAssignment->source_academic_year_id)->toBe($this->activeAy->id)
        ->and($sppAssignment->created_by)->toBeNull();
});

test('PSB approval skips inactive fee_types and fee_types without schedule', function () {
    // Inactive fee_type with schedule — should be skipped.
    $inactive = FeeType::factory()->forSchool($this->school)->inactive()
        ->create(['cash_book_category_id' => $this->category->id]);
    FeeSchedule::factory()->forSchool($this->school)->forAcademicYear($this->activeAy)->forFeeType($inactive)
        ->create(['amount' => 100000]);

    // Active fee_type WITHOUT schedule — should be skipped (FR-009 partial).
    FeeType::factory()->forSchool($this->school)
        ->create(['code' => 'orphan', 'cash_book_category_id' => $this->category->id]);

    $period = RegistrationPeriod::factory()->forSchool($this->school)->forAcademicYear($this->activeAy)->create();
    $reg = Registration::factory()->create([
        'school_id' => $this->school->id,
        'registration_period_id' => $period->id,
        'status' => Registration::STATUS_NEW,
    ]);
    $admin = makeStudentFeeManager();

    $this->actingAs($admin)
        ->postJson("/api/v1/psb/registrations/{$reg->id}/accept", ['class_level' => 'ibtida_1'])
        ->assertOk();

    $student = Student::where('registration_id', $reg->id)->first();
    expect($student->feeAssignments()->count())->toBe(2); // only spp + daftar
});

test('PSB approval hard-fails 422 when zero active AYs', function () {
    $this->activeAy->update(['is_active' => false]);

    $period = RegistrationPeriod::factory()->forSchool($this->school)->forAcademicYear($this->activeAy)->create();
    $reg = Registration::factory()->create([
        'school_id' => $this->school->id,
        'registration_period_id' => $period->id,
        'status' => Registration::STATUS_NEW,
    ]);
    $admin = makeStudentFeeManager();

    $response = $this->actingAs($admin)
        ->postJson("/api/v1/psb/registrations/{$reg->id}/accept", ['class_level' => 'ibtida_1']);

    $response->assertUnprocessable()
        ->assertJsonPath('message', 'Tidak dapat memproses penerimaan: tahun ajaran aktif tidak valid (harus tepat 1 AY berstatus aktif).');

    expect(Student::where('registration_id', $reg->id)->exists())->toBeFalse();
});

test('PSB approval hard-fails 422 when more than 1 active AY', function () {
    AcademicYear::factory()->create(['school_id' => $this->school->id, 'is_active' => true]);

    $period = RegistrationPeriod::factory()->forSchool($this->school)->forAcademicYear($this->activeAy)->create();
    $reg = Registration::factory()->create([
        'school_id' => $this->school->id,
        'registration_period_id' => $period->id,
        'status' => Registration::STATUS_NEW,
    ]);
    $admin = makeStudentFeeManager();

    $this->actingAs($admin)
        ->postJson("/api/v1/psb/registrations/{$reg->id}/accept", ['class_level' => 'ibtida_1'])
        ->assertUnprocessable();

    expect(Student::where('registration_id', $reg->id)->exists())->toBeFalse();
});

test('PSB auto-snapshot writes audit log with actor_kind=psb_hook, actor_id=null', function () {
    $period = RegistrationPeriod::factory()->forSchool($this->school)->forAcademicYear($this->activeAy)->create();
    $reg = Registration::factory()->create([
        'school_id' => $this->school->id,
        'registration_period_id' => $period->id,
        'status' => Registration::STATUS_NEW,
    ]);
    $admin = makeStudentFeeManager();

    $this->actingAs($admin)
        ->postJson("/api/v1/psb/registrations/{$reg->id}/accept", ['class_level' => 'ibtida_1'])
        ->assertOk();

    $logs = FeeActivityLog::where('subject_type', FeeActivityLog::SUBJECT_ASSIGNMENT)->get();
    expect($logs)->toHaveCount(2);
    foreach ($logs as $log) {
        expect($log->actor_kind)->toBe(FeeActivityLog::ACTOR_PSB_HOOK)
            ->and($log->actor_id)->toBeNull();
    }
});

// ──────────────────────────────────────────────────────
// Manual snapshot via AY referensi
// ──────────────────────────────────────────────────────

test('manager can manual-snapshot a legacy student with AY referensi', function () {
    $student = Student::factory()->create(['school_id' => $this->school->id]);
    $admin = makeStudentFeeManager();

    $this->actingAs($admin)
        ->postJson("/api/v1/students/{$student->id}/fee-assignments/snapshot", [
            'academic_year_id' => $this->activeAy->id,
        ])
        ->assertCreated()
        ->assertJsonPath('data.created_count', 2)
        ->assertJsonPath('data.skipped_count', 0);

    $assignments = $student->feeAssignments()->get();
    expect($assignments)->toHaveCount(2);
    foreach ($assignments as $a) {
        expect($a->source)->toBe(StudentFeeAssignment::SOURCE_MANUAL_SNAPSHOT)
            ->and($a->created_by)->toBe($admin->id);
    }
});

test('manual snapshot is idempotent — skips fee_types already assigned', function () {
    $student = Student::factory()->create(['school_id' => $this->school->id]);
    StudentFeeAssignment::factory()->forStudent($student)->forFeeType($this->spp)->forAcademicYear($this->activeAy)
        ->create(['locked_amount' => 400000]);
    $admin = makeStudentFeeManager();

    $this->actingAs($admin)
        ->postJson("/api/v1/students/{$student->id}/fee-assignments/snapshot", [
            'academic_year_id' => $this->activeAy->id,
        ])
        ->assertCreated()
        ->assertJsonPath('data.created_count', 1)  // only daftar created
        ->assertJsonPath('data.skipped_count', 1); // spp skipped

    expect($student->feeAssignments()->count())->toBe(2);
    expect((int) $student->feeAssignments()->where('fee_type_id', $this->spp->id)->value('locked_amount'))
        ->toBe(400000); // existing not overwritten
});

test('manual snapshot rejects AY with no schedules', function () {
    $emptyAy = AcademicYear::factory()->create(['school_id' => $this->school->id]);
    $student = Student::factory()->create(['school_id' => $this->school->id]);
    $admin = makeStudentFeeManager();

    $this->actingAs($admin)
        ->postJson("/api/v1/students/{$student->id}/fee-assignments/snapshot", [
            'academic_year_id' => $emptyAy->id,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Tidak ada tarif tersimpan untuk tahun ajaran ini. Pilih tahun ajaran lain atau gunakan mode "Input Manual".');
});

// ──────────────────────────────────────────────────────
// Manual entry (per fee_type input)
// ──────────────────────────────────────────────────────

test('manager can set manual entries for a student', function () {
    $student = Student::factory()->create(['school_id' => $this->school->id]);
    $admin = makeStudentFeeManager();

    $this->actingAs($admin)
        ->postJson("/api/v1/students/{$student->id}/fee-assignments/manual", [
            'source_academic_year_id' => $this->activeAy->id,
            'items' => [
                ['fee_type_id' => $this->spp->id, 'locked_amount' => 450000, 'cadence' => 'monthly'],
                ['fee_type_id' => $this->daftar->id, 'locked_amount' => 4000000, 'cadence' => 'once_at_enrollment'],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.created_count', 2);

    $sppAssignment = $student->feeAssignments()->where('fee_type_id', $this->spp->id)->first();
    expect((int) $sppAssignment->locked_amount)->toBe(450000)
        ->and($sppAssignment->source)->toBe(StudentFeeAssignment::SOURCE_MANUAL_ENTRY);
});

test('manual entry validates required fields', function () {
    $student = Student::factory()->create(['school_id' => $this->school->id]);
    $admin = makeStudentFeeManager();

    $this->actingAs($admin)
        ->postJson("/api/v1/students/{$student->id}/fee-assignments/manual", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['source_academic_year_id', 'items']);
});

// ──────────────────────────────────────────────────────
// Constraints
// ──────────────────────────────────────────────────────

test('duplicate assignment (same student × fee_type) is blocked', function () {
    $student = Student::factory()->create(['school_id' => $this->school->id]);
    StudentFeeAssignment::factory()->forStudent($student)->forFeeType($this->spp)->forAcademicYear($this->activeAy)
        ->create();

    expect(fn () => StudentFeeAssignment::factory()->forStudent($student)->forFeeType($this->spp)->forAcademicYear($this->activeAy)
        ->create())
        ->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
});

test('list assignments endpoint returns student fee assignments with eager loaded refs', function () {
    $student = Student::factory()->create(['school_id' => $this->school->id]);
    StudentFeeAssignment::factory()->forStudent($student)->forFeeType($this->spp)->forAcademicYear($this->activeAy)
        ->create(['locked_amount' => 500000]);
    $admin = makeStudentFeeManager();

    $this->actingAs($admin)
        ->getJson("/api/v1/students/{$student->id}/fee-assignments")
        ->assertOk()
        ->assertJsonPath('data.0.locked_amount', 500000)
        ->assertJsonPath('data.0.fee_type.code', 'spp')
        ->assertJsonPath('data.0.source_academic_year.name', $this->activeAy->name)
        ->assertJsonPath('data.0.effective_amount_current_period', 500000);
});

// ──────────────────────────────────────────────────────
// Multi-tenant + permission
// ──────────────────────────────────────────────────────

test('cross-tenant student access returns 404', function () {
    $otherSchool = School::factory()->create(['is_active' => false]);
    $otherStudent = Student::factory()->create(['school_id' => $otherSchool->id]);
    $admin = makeStudentFeeManager();

    $this->actingAs($admin)
        ->getJson("/api/v1/students/{$otherStudent->id}/fee-assignments")
        ->assertNotFound();
});

test('user without view-student-fees gets 403 on list', function () {
    $student = Student::factory()->create(['school_id' => $this->school->id]);
    $user = makeUnprivilegedFeeUser();

    $this->actingAs($user)
        ->getJson("/api/v1/students/{$student->id}/fee-assignments")
        ->assertForbidden();
});

test('user without manage-student-fees gets 403 on snapshot', function () {
    $student = Student::factory()->create(['school_id' => $this->school->id]);
    $user = makeUnprivilegedFeeUser();

    $this->actingAs($user)
        ->postJson("/api/v1/students/{$student->id}/fee-assignments/snapshot", [
            'academic_year_id' => $this->activeAy->id,
        ])
        ->assertForbidden();
});

// ──────────────────────────────────────────────────────
// Unassigned students endpoints
// ──────────────────────────────────────────────────────

test('unassigned-students count returns active students without any assignment', function () {
    Student::factory()->count(3)->create(['school_id' => $this->school->id]);
    $withFee = Student::factory()->create(['school_id' => $this->school->id]);
    StudentFeeAssignment::factory()->forStudent($withFee)->forFeeType($this->spp)->forAcademicYear($this->activeAy)
        ->create();
    $admin = makeStudentFeeManager();

    $this->actingAs($admin)
        ->getJson('/api/v1/fees/unassigned-students/count')
        ->assertOk()
        ->assertJsonPath('data.count', 3);
});

test('unassigned-students list ignores graduated/withdrawn and other-school students', function () {
    Student::factory()->create(['school_id' => $this->school->id]); // active no fee
    Student::factory()->create(['school_id' => $this->school->id, 'status' => 'graduated']);
    Student::factory()->create(['school_id' => $this->school->id, 'status' => 'withdrawn']);

    $otherSchool = School::factory()->create(['is_active' => false]);
    Student::factory()->create(['school_id' => $otherSchool->id]); // different school
    $admin = makeStudentFeeManager();

    $this->actingAs($admin)
        ->getJson('/api/v1/fees/unassigned-students')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
});
