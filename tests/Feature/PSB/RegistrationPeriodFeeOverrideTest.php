<?php

use App\Models\AcademicYear;
use App\Models\CashBookCategory;
use App\Models\FeeActivityLog;
use App\Models\FeeSchedule;
use App\Models\FeeType;
use App\Models\RegistrationPeriod;
use App\Models\RegistrationPeriodFeeOverride;
use App\Models\School;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->school = School::factory()->create(['is_active' => true]);
    $this->academicYear = AcademicYear::factory()->create(['school_id' => $this->school->id]);
    $this->period = RegistrationPeriod::factory()
        ->forSchool($this->school)
        ->forAcademicYear($this->academicYear)
        ->create();

    // Default "once_at_enrollment" fee type so most happy-path tests can reuse it.
    $this->cashBookCategory = CashBookCategory::factory()
        ->for($this->school, 'school')
        ->create();
    $this->pendaftaranFeeType = FeeType::factory()
        ->forSchool($this->school)
        ->withCadence(FeeType::CADENCE_ONCE_AT_ENROLLMENT)
        ->create([
            'cash_book_category_id' => $this->cashBookCategory->id,
            'label' => 'Pendaftaran',
        ]);

    // Default fee_schedule wajib ada sebelum override boleh dibuat — guard
    // baru di service (R3 review fix BE#8). Tests yang sengaja test missing
    // schedule akan delete row ini sebelum POST.
    FeeSchedule::factory()
        ->forSchool($this->school)
        ->forAcademicYear($this->academicYear)
        ->forFeeType($this->pendaftaranFeeType)
        ->create(['amount' => 500000]);
});

function makePeriodOverrideManager(): User
{
    $user = User::factory()->create();
    // Manager realistically holds both view + manage — the GET list route
    // is gated by view-registration-periods, the mutating routes by
    // manage-registration-periods. Real admins (pengurus_pesantren) have both.
    $user->givePermissionTo('view-registration-periods');
    $user->givePermissionTo('manage-registration-periods');

    return $user;
}

function makePeriodOverrideViewer(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('view-registration-periods');

    return $user;
}

function makeUnprivilegedPeriodUser(): User
{
    return User::factory()->create();
}

// ─── Authentication / Permission ─────────────────────────────────────

test('unauthenticated user cannot access fee override endpoints', function () {
    $this->getJson("/api/v1/psb/periods/{$this->period->id}/fee-overrides")->assertUnauthorized();
    $this->postJson("/api/v1/psb/periods/{$this->period->id}/fee-overrides")->assertUnauthorized();
});

test('user without permission cannot list overrides', function () {
    $this->actingAs(makeUnprivilegedPeriodUser())
        ->getJson("/api/v1/psb/periods/{$this->period->id}/fee-overrides")
        ->assertForbidden();
});

test('user without manage-registration-periods cannot create override', function () {
    $this->actingAs(makePeriodOverrideViewer())
        ->postJson("/api/v1/psb/periods/{$this->period->id}/fee-overrides", [
            'fee_type_id' => $this->pendaftaranFeeType->id,
            'amount' => 200000,
            'reason' => 'test',
        ])
        ->assertForbidden();
});

// ─── List ────────────────────────────────────────────────────────────

test('lists overrides for a period', function () {
    RegistrationPeriodFeeOverride::factory()
        ->forPeriod($this->period)
        ->forFeeType($this->pendaftaranFeeType)
        ->create();

    $response = $this->actingAs(makePeriodOverrideManager())
        ->getJson("/api/v1/psb/periods/{$this->period->id}/fee-overrides")
        ->assertOk();

    expect(count($response->json('data')))->toBe(1);
});

test('list scoped to active school only — cross-school period returns 404', function () {
    $otherSchool = School::factory()->create();
    $otherAY = AcademicYear::factory()->create(['school_id' => $otherSchool->id]);
    $otherPeriod = RegistrationPeriod::factory()->forSchool($otherSchool)->forAcademicYear($otherAY)->create();

    $this->actingAs(makePeriodOverrideManager())
        ->getJson("/api/v1/psb/periods/{$otherPeriod->id}/fee-overrides")
        ->assertNotFound();
});

// ─── Create ──────────────────────────────────────────────────────────

test('manager can create override for once_at_enrollment fee_type', function () {
    $payload = [
        'fee_type_id' => $this->pendaftaranFeeType->id,
        'amount' => 200000,
        'reason' => 'Komite rapat 5 Mei 2026 — gelombang awal promo',
    ];

    $this->actingAs(makePeriodOverrideManager())
        ->postJson("/api/v1/psb/periods/{$this->period->id}/fee-overrides", $payload)
        ->assertCreated()
        ->assertJsonPath('data.amount', 200000)
        ->assertJsonPath('data.reason', 'Komite rapat 5 Mei 2026 — gelombang awal promo')
        ->assertJsonPath('data.fee_type.id', $this->pendaftaranFeeType->id);

    expect(RegistrationPeriodFeeOverride::where('registration_period_id', $this->period->id)
        ->where('fee_type_id', $this->pendaftaranFeeType->id)
        ->exists())->toBeTrue();
});

test('create rejects fee_type that is NOT once_at_enrollment cadence', function () {
    $sppFeeType = FeeType::factory()
        ->forSchool($this->school)
        ->withCadence(FeeType::CADENCE_MONTHLY)
        ->create([
            'cash_book_category_id' => $this->cashBookCategory->id,
            'label' => 'SPP',
        ]);

    $this->actingAs(makePeriodOverrideManager())
        ->postJson("/api/v1/psb/periods/{$this->period->id}/fee-overrides", [
            'fee_type_id' => $sppFeeType->id,
            'amount' => 100000,
            'reason' => 'test',
        ])
        ->assertStatus(422);
});

test('create rejects missing reason (audit trail wajib)', function () {
    $this->actingAs(makePeriodOverrideManager())
        ->postJson("/api/v1/psb/periods/{$this->period->id}/fee-overrides", [
            'fee_type_id' => $this->pendaftaranFeeType->id,
            'amount' => 200000,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);
});

test('create rejects duplicate override for same (period × fee_type)', function () {
    RegistrationPeriodFeeOverride::factory()
        ->forPeriod($this->period)
        ->forFeeType($this->pendaftaranFeeType)
        ->create();

    $this->actingAs(makePeriodOverrideManager())
        ->postJson("/api/v1/psb/periods/{$this->period->id}/fee-overrides", [
            'fee_type_id' => $this->pendaftaranFeeType->id,
            'amount' => 200000,
            'reason' => 'test',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['fee_type_id']);
});

test('create rejects fee_type that has no schedule for period AY (silent-loss guard)', function () {
    // fee_type valid (school + cadence OK), tapi belum di-set tarif untuk AY periode.
    $otherFeeType = FeeType::factory()
        ->forSchool($this->school)
        ->withCadence(FeeType::CADENCE_ONCE_AT_ENROLLMENT)
        ->create([
            'cash_book_category_id' => $this->cashBookCategory->id,
            'label' => 'Uang Pangkal',
        ]);
    // No FeeSchedule for ($this->academicYear, $otherFeeType) deliberately.

    $this->actingAs(makePeriodOverrideManager())
        ->postJson("/api/v1/psb/periods/{$this->period->id}/fee-overrides", [
            'fee_type_id' => $otherFeeType->id,
            'amount' => 200000,
            'reason' => 'test',
        ])
        ->assertStatus(422);
});

test('create rejects fee_type from another school', function () {
    $otherSchool = School::factory()->create();
    $otherCategory = CashBookCategory::factory()->for($otherSchool, 'school')->create();
    $otherFeeType = FeeType::factory()
        ->forSchool($otherSchool)
        ->withCadence(FeeType::CADENCE_ONCE_AT_ENROLLMENT)
        ->create(['cash_book_category_id' => $otherCategory->id]);

    $this->actingAs(makePeriodOverrideManager())
        ->postJson("/api/v1/psb/periods/{$this->period->id}/fee-overrides", [
            'fee_type_id' => $otherFeeType->id,
            'amount' => 200000,
            'reason' => 'test',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['fee_type_id']);
});

test('create accepts amount=0 (free promo)', function () {
    $this->actingAs(makePeriodOverrideManager())
        ->postJson("/api/v1/psb/periods/{$this->period->id}/fee-overrides", [
            'fee_type_id' => $this->pendaftaranFeeType->id,
            'amount' => 0,
            'reason' => 'Gratis untuk yatim piatu',
        ])
        ->assertCreated()
        ->assertJsonPath('data.amount', 0);
});

// ─── Update ──────────────────────────────────────────────────────────

test('manager can update amount + reason', function () {
    $override = RegistrationPeriodFeeOverride::factory()
        ->forPeriod($this->period)
        ->forFeeType($this->pendaftaranFeeType)
        ->create(['amount' => 200000, 'reason' => 'awal']);

    $this->actingAs(makePeriodOverrideManager())
        ->patchJson("/api/v1/psb/periods/{$this->period->id}/fee-overrides/{$override->id}", [
            'amount' => 300000,
            'reason' => 'revisi setelah rapat lanjutan',
        ])
        ->assertOk()
        ->assertJsonPath('data.amount', 300000)
        ->assertJsonPath('data.reason', 'revisi setelah rapat lanjutan');
});

test('update rejects override of another period (URL traversal guard)', function () {
    $otherPeriod = RegistrationPeriod::factory()
        ->forSchool($this->school)
        ->forAcademicYear($this->academicYear)
        ->create();
    $override = RegistrationPeriodFeeOverride::factory()
        ->forPeriod($otherPeriod)
        ->forFeeType($this->pendaftaranFeeType)
        ->create();

    $this->actingAs(makePeriodOverrideManager())
        ->patchJson("/api/v1/psb/periods/{$this->period->id}/fee-overrides/{$override->id}", [
            'amount' => 999999,
        ])
        ->assertNotFound();
});

// ─── Delete ──────────────────────────────────────────────────────────

test('manager can delete override', function () {
    $override = RegistrationPeriodFeeOverride::factory()
        ->forPeriod($this->period)
        ->forFeeType($this->pendaftaranFeeType)
        ->create();

    $this->actingAs(makePeriodOverrideManager())
        ->deleteJson("/api/v1/psb/periods/{$this->period->id}/fee-overrides/{$override->id}")
        ->assertOk();

    expect(RegistrationPeriodFeeOverride::find($override->id))->toBeNull();
});

// ─── Audit Log ───────────────────────────────────────────────────────

test('create writes audit log with subject_type=period_override', function () {
    $user = makePeriodOverrideManager();

    $this->actingAs($user)
        ->postJson("/api/v1/psb/periods/{$this->period->id}/fee-overrides", [
            'fee_type_id' => $this->pendaftaranFeeType->id,
            'amount' => 200000,
            'reason' => 'audit test',
        ])
        ->assertCreated();

    $log = FeeActivityLog::where('subject_type', FeeActivityLog::SUBJECT_PERIOD_OVERRIDE)
        ->where('action', FeeActivityLog::ACTION_CREATED)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->actor_kind)->toBe(FeeActivityLog::ACTOR_USER)
        ->and($log->actor_id)->toBe($user->id);
});

test('update rejects empty reason (audit trail guard)', function () {
    $override = RegistrationPeriodFeeOverride::factory()
        ->forPeriod($this->period)
        ->forFeeType($this->pendaftaranFeeType)
        ->create();

    $this->actingAs(makePeriodOverrideManager())
        ->patchJson("/api/v1/psb/periods/{$this->period->id}/fee-overrides/{$override->id}", [
            'reason' => '',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);
});

test('update writes audit log with before/after diff in changes', function () {
    $override = RegistrationPeriodFeeOverride::factory()
        ->forPeriod($this->period)
        ->forFeeType($this->pendaftaranFeeType)
        ->create(['amount' => 200000]);

    $this->actingAs(makePeriodOverrideManager())
        ->patchJson("/api/v1/psb/periods/{$this->period->id}/fee-overrides/{$override->id}", [
            'amount' => 300000,
        ]);

    $log = FeeActivityLog::where('subject_id', $override->id)
        ->where('action', FeeActivityLog::ACTION_UPDATED)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->changes['after']['amount'] ?? null)->toBe(300000);
});
