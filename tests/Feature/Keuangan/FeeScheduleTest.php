<?php

use App\Models\AcademicYear;
use App\Models\CashBookCategory;
use App\Models\FeeSchedule;
use App\Models\FeeType;
use App\Models\School;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    foreach (['manage-fee-types', 'manage-fee-schedules', 'manage-student-fees', 'view-student-fees', 'record-payments'] as $perm) {
        Permission::firstOrCreate(['name' => $perm]);
    }

    $this->school = School::factory()->create(['is_active' => true]);
    $this->academicYear = AcademicYear::factory()->create(['school_id' => $this->school->id]);
    $this->category = CashBookCategory::factory()->for($this->school, 'school')->create();
    $this->feeType = FeeType::factory()->forSchool($this->school)->create(['code' => 'spp']);
});

function makeFeeScheduleManager(): User
{
    $user = User::factory()->create();
    $role = Role::firstOrCreate(['name' => 'pengurus_pesantren']);
    $role->syncPermissions([
        'manage-fee-types',
        'manage-fee-schedules',
        'manage-student-fees',
        'view-student-fees',
        'record-payments',
    ]);
    $user->assignRole($role);

    return $user;
}

function makeUnprivilegedScheduleUser(): User
{
    $user = User::factory()->create();
    $role = Role::firstOrCreate(['name' => 'no_schedule_access']);
    $role->syncPermissions([]);
    $user->assignRole($role);

    return $user;
}

// ─── Permission ──────────────────────────────────────────────────────

test('unauthenticated user cannot list schedules', function () {
    $this->getJson('/api/v1/fee-schedules')->assertUnauthorized();
});

test('user without manage-fee-schedules cannot list', function () {
    $this->actingAs(makeUnprivilegedScheduleUser())
        ->getJson('/api/v1/fee-schedules')
        ->assertForbidden();
});

// ─── List ────────────────────────────────────────────────────────────

test('lists schedules scoped to active school', function () {
    FeeSchedule::factory()->forSchool($this->school)
        ->forFeeType($this->feeType)
        ->forAcademicYear($this->academicYear)
        ->create();

    $otherSchool = School::factory()->create();
    $otherAY = AcademicYear::factory()->create(['school_id' => $otherSchool->id]);
    $otherFeeType = FeeType::factory()->forSchool($otherSchool)->create();
    FeeSchedule::factory()->forSchool($otherSchool)->forFeeType($otherFeeType)->forAcademicYear($otherAY)->create();

    $this->actingAs(makeFeeScheduleManager())
        ->getJson('/api/v1/fee-schedules')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('lists can filter by academic_year_id', function () {
    $otherAY = AcademicYear::factory()->create(['school_id' => $this->school->id]);

    FeeSchedule::factory()->forSchool($this->school)->forFeeType($this->feeType)->forAcademicYear($this->academicYear)->create();
    FeeSchedule::factory()->forSchool($this->school)
        ->forFeeType(FeeType::factory()->forSchool($this->school)->create(['code' => 'uang_gedung']))
        ->forAcademicYear($otherAY)
        ->create();

    $this->actingAs(makeFeeScheduleManager())
        ->getJson("/api/v1/fee-schedules?academic_year_id={$this->academicYear->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

// ─── Create ──────────────────────────────────────────────────────────

test('manager can create schedule for AY × fee_type combination', function () {
    $this->actingAs(makeFeeScheduleManager())
        ->postJson('/api/v1/fee-schedules', [
            'academic_year_id' => $this->academicYear->id,
            'fee_type_id' => $this->feeType->id,
            'amount' => 500000,
        ])
        ->assertCreated()
        ->assertJsonPath('data.amount', 500000);

    expect(FeeSchedule::where('academic_year_id', $this->academicYear->id)
        ->where('fee_type_id', $this->feeType->id)
        ->exists())->toBeTrue();
});

test('create rejects duplicate (AY × fee_type) combination', function () {
    FeeSchedule::factory()->forSchool($this->school)
        ->forFeeType($this->feeType)
        ->forAcademicYear($this->academicYear)
        ->create(['amount' => 400000]);

    $this->actingAs(makeFeeScheduleManager())
        ->postJson('/api/v1/fee-schedules', [
            'academic_year_id' => $this->academicYear->id,
            'fee_type_id' => $this->feeType->id,
            'amount' => 600000,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['fee_type_id']);
});

test('create accepts amount=0 (free fee type)', function () {
    $this->actingAs(makeFeeScheduleManager())
        ->postJson('/api/v1/fee-schedules', [
            'academic_year_id' => $this->academicYear->id,
            'fee_type_id' => $this->feeType->id,
            'amount' => 0,
        ])
        ->assertCreated()
        ->assertJsonPath('data.amount', 0);
});

test('create rejects negative amount', function () {
    $this->actingAs(makeFeeScheduleManager())
        ->postJson('/api/v1/fee-schedules', [
            'academic_year_id' => $this->academicYear->id,
            'fee_type_id' => $this->feeType->id,
            'amount' => -1,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['amount']);
});

test('create rejects fee_type_id from another school', function () {
    $otherSchool = School::factory()->create();
    $otherFeeType = FeeType::factory()->forSchool($otherSchool)->create();

    $this->actingAs(makeFeeScheduleManager())
        ->postJson('/api/v1/fee-schedules', [
            'academic_year_id' => $this->academicYear->id,
            'fee_type_id' => $otherFeeType->id,
            'amount' => 500000,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['fee_type_id']);
});

test('create rejects academic_year_id from another school', function () {
    $otherSchool = School::factory()->create();
    $otherAY = AcademicYear::factory()->create(['school_id' => $otherSchool->id]);

    $this->actingAs(makeFeeScheduleManager())
        ->postJson('/api/v1/fee-schedules', [
            'academic_year_id' => $otherAY->id,
            'fee_type_id' => $this->feeType->id,
            'amount' => 500000,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['academic_year_id']);
});

// ─── Update ──────────────────────────────────────────────────────────

test('manager can update amount; effect only on future snapshots (locked semantics)', function () {
    $schedule = FeeSchedule::factory()->forSchool($this->school)
        ->forFeeType($this->feeType)
        ->forAcademicYear($this->academicYear)
        ->create(['amount' => 500000]);

    $this->actingAs(makeFeeScheduleManager())
        ->patchJson("/api/v1/fee-schedules/{$schedule->id}", ['amount' => 700000])
        ->assertOk()
        ->assertJsonPath('data.amount', 700000);

    // Note: locked semantics (no retroactive impact on student_fee_assignments)
    // verified in US2 tests once assignments exist. Here we only assert schedule
    // update succeeds.
});

test('update rejects cross-school schedule with 404', function () {
    $otherSchool = School::factory()->create();
    $otherAY = AcademicYear::factory()->create(['school_id' => $otherSchool->id]);
    $otherFeeType = FeeType::factory()->forSchool($otherSchool)->create();
    $otherSchedule = FeeSchedule::factory()->forSchool($otherSchool)
        ->forFeeType($otherFeeType)
        ->forAcademicYear($otherAY)
        ->create();

    $this->actingAs(makeFeeScheduleManager())
        ->patchJson("/api/v1/fee-schedules/{$otherSchedule->id}", ['amount' => 999999])
        ->assertNotFound();
});

// ─── Delete ──────────────────────────────────────────────────────────

test('manager can delete schedule', function () {
    $schedule = FeeSchedule::factory()->forSchool($this->school)
        ->forFeeType($this->feeType)
        ->forAcademicYear($this->academicYear)
        ->create();

    $this->actingAs(makeFeeScheduleManager())
        ->deleteJson("/api/v1/fee-schedules/{$schedule->id}")
        ->assertOk();

    expect(FeeSchedule::find($schedule->id))->toBeNull();
});

test('delete rejects cross-school with 404', function () {
    $otherSchool = School::factory()->create();
    $otherAY = AcademicYear::factory()->create(['school_id' => $otherSchool->id]);
    $otherFeeType = FeeType::factory()->forSchool($otherSchool)->create();
    $otherSchedule = FeeSchedule::factory()->forSchool($otherSchool)
        ->forFeeType($otherFeeType)
        ->forAcademicYear($otherAY)
        ->create();

    $this->actingAs(makeFeeScheduleManager())
        ->deleteJson("/api/v1/fee-schedules/{$otherSchedule->id}")
        ->assertNotFound();
});
