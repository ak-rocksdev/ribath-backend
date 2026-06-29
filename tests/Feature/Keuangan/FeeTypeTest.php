<?php

use App\Models\CashBookCategory;
use App\Models\FeeActivityLog;
use App\Models\FeeSchedule;
use App\Models\FeeType;
use App\Models\School;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    // Permissions: only `manage-fee-types` is asserted at the route level.
    // The other fee permissions are seeded for completeness so the
    // pengurus_pesantren role mirrors production reality.
    foreach (['manage-fee-types', 'manage-fee-schedules', 'manage-student-fees', 'view-student-fees', 'record-payments'] as $perm) {
        Permission::firstOrCreate(['name' => $perm]);
    }

    $this->school = School::factory()->create(['is_active' => true]);
    $this->category = CashBookCategory::factory()->for($this->school, 'school')->create([
        'name' => 'SPP',
    ]);
});

function makeFeeManager(): User
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

function makeUnprivilegedUser(): User
{
    $user = User::factory()->create();
    $role = Role::firstOrCreate(['name' => 'no_fee_access']);
    $role->syncPermissions([]);
    $user->assignRole($role);

    return $user;
}

// ─── Authentication / Permission ─────────────────────────────────────

test('unauthenticated user cannot list fee types', function () {
    $this->getJson('/api/v1/fee-types')->assertUnauthorized();
});

test('user without manage-fee-types cannot list', function () {
    $this->actingAs(makeUnprivilegedUser())
        ->getJson('/api/v1/fee-types')
        ->assertForbidden();
});

test('user without manage-fee-types cannot create', function () {
    $this->actingAs(makeUnprivilegedUser())
        ->postJson('/api/v1/fee-types', [
            'code' => 'spp',
            'label' => 'SPP',
            'default_cadence' => 'monthly',
            'cash_book_category_id' => $this->category->id,
        ])
        ->assertForbidden();
});

// ─── List ────────────────────────────────────────────────────────────

test('lists fee types scoped to active school only', function () {
    FeeType::factory()->forSchool($this->school)->count(2)->create();

    $otherSchool = School::factory()->create(['is_active' => false]);
    FeeType::factory()->forSchool($otherSchool)->count(3)->create();

    $this->actingAs(makeFeeManager())
        ->getJson('/api/v1/fee-types')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('lists fee types ordered by code ascending', function () {
    FeeType::factory()->forSchool($this->school)->create(['code' => 'zzz']);
    FeeType::factory()->forSchool($this->school)->create(['code' => 'aaa']);
    FeeType::factory()->forSchool($this->school)->create(['code' => 'mmm']);

    $response = $this->actingAs(makeFeeManager())
        ->getJson('/api/v1/fee-types')
        ->assertOk();

    $codes = collect($response->json('data'))->pluck('code')->all();

    expect($codes)->toBe(['aaa', 'mmm', 'zzz']);
});

test('lists can filter by is_active', function () {
    FeeType::factory()->forSchool($this->school)->create(['is_active' => true]);
    FeeType::factory()->forSchool($this->school)->inactive()->create();

    $this->actingAs(makeFeeManager())
        ->getJson('/api/v1/fee-types?is_active=true')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs(makeFeeManager())
        ->getJson('/api/v1/fee-types?is_active=false')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

// ─── Create ──────────────────────────────────────────────────────────

test('manager can create fee type with required category', function () {
    $payload = [
        'code' => 'spp',
        'label' => 'SPP Bulanan',
        'default_cadence' => 'monthly',
        'cash_book_category_id' => $this->category->id,
    ];

    $this->actingAs(makeFeeManager())
        ->postJson('/api/v1/fee-types', $payload)
        ->assertCreated()
        ->assertJsonPath('data.code', 'spp')
        ->assertJsonPath('data.label', 'SPP Bulanan')
        ->assertJsonPath('data.cash_book_category.id', $this->category->id);

    expect(FeeType::where('code', 'spp')->where('school_id', $this->school->id)->exists())->toBeTrue();
});

test('create rejects missing cash_book_category_id (Clarifications Q3 — required, no fallback)', function () {
    $this->actingAs(makeFeeManager())
        ->postJson('/api/v1/fee-types', [
            'code' => 'spp',
            'label' => 'SPP',
            'default_cadence' => 'monthly',
            // cash_book_category_id deliberately omitted
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['cash_book_category_id']);
});

test('create rejects cash_book_category_id from other school (no cross-tenant leak)', function () {
    $otherSchool = School::factory()->create();
    $otherCategory = CashBookCategory::factory()->for($otherSchool, 'school')->create();

    $this->actingAs(makeFeeManager())
        ->postJson('/api/v1/fee-types', [
            'code' => 'spp',
            'label' => 'SPP',
            'default_cadence' => 'monthly',
            'cash_book_category_id' => $otherCategory->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['cash_book_category_id']);
});

test('create rejects duplicate code within same school', function () {
    FeeType::factory()->forSchool($this->school)->create(['code' => 'spp']);

    $this->actingAs(makeFeeManager())
        ->postJson('/api/v1/fee-types', [
            'code' => 'spp',
            'label' => 'SPP Duplicate',
            'default_cadence' => 'monthly',
            'cash_book_category_id' => $this->category->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code']);
});

test('create allows same code across different schools', function () {
    $otherSchool = School::factory()->create();
    FeeType::factory()->forSchool($otherSchool)->create(['code' => 'spp']);

    $this->actingAs(makeFeeManager())
        ->postJson('/api/v1/fee-types', [
            'code' => 'spp',
            'label' => 'SPP (this school)',
            'default_cadence' => 'monthly',
            'cash_book_category_id' => $this->category->id,
        ])
        ->assertCreated();
});

test('create rejects invalid cadence', function () {
    $this->actingAs(makeFeeManager())
        ->postJson('/api/v1/fee-types', [
            'code' => 'spp',
            'label' => 'SPP',
            'default_cadence' => 'weekly',  // not in enum
            'cash_book_category_id' => $this->category->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['default_cadence']);
});

test('create rejects invalid code format', function () {
    $this->actingAs(makeFeeManager())
        ->postJson('/api/v1/fee-types', [
            'code' => 'SPP-Bulanan!',  // uppercase + dash + special char
            'label' => 'SPP',
            'default_cadence' => 'monthly',
            'cash_book_category_id' => $this->category->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code']);
});

// ─── Update ──────────────────────────────────────────────────────────

test('manager can update label and cadence', function () {
    $feeType = FeeType::factory()->forSchool($this->school)->create(['label' => 'Old']);

    $this->actingAs(makeFeeManager())
        ->patchJson("/api/v1/fee-types/{$feeType->id}", [
            'label' => 'New Label',
            'default_cadence' => 'yearly',
        ])
        ->assertOk()
        ->assertJsonPath('data.label', 'New Label')
        ->assertJsonPath('data.default_cadence', 'yearly');
});

test('update silently ignores code field (immutable)', function () {
    $feeType = FeeType::factory()->forSchool($this->school)->create(['code' => 'original']);

    $this->actingAs(makeFeeManager())
        ->patchJson("/api/v1/fee-types/{$feeType->id}", [
            'code' => 'attempted_change',
            'label' => 'still updates',
        ])
        ->assertOk()
        ->assertJsonPath('data.code', 'original')   // unchanged
        ->assertJsonPath('data.label', 'still updates');
});

test('update rejects cross-school fee type with 404 (hide existence)', function () {
    $otherSchool = School::factory()->create();
    $otherFeeType = FeeType::factory()->forSchool($otherSchool)->create();

    $this->actingAs(makeFeeManager())
        ->patchJson("/api/v1/fee-types/{$otherFeeType->id}", ['label' => 'hack'])
        ->assertNotFound();
});

// ─── Delete ──────────────────────────────────────────────────────────

test('manager can delete fee type with no dependencies', function () {
    $feeType = FeeType::factory()->forSchool($this->school)->create();

    $this->actingAs(makeFeeManager())
        ->deleteJson("/api/v1/fee-types/{$feeType->id}")
        ->assertOk();

    expect(FeeType::find($feeType->id))->toBeNull();
});

test('delete rejected when fee_schedules reference this fee_type (409)', function () {
    $feeType = FeeType::factory()->forSchool($this->school)->create();
    FeeSchedule::factory()->forSchool($this->school)->forFeeType($feeType)->create();

    $this->actingAs(makeFeeManager())
        ->deleteJson("/api/v1/fee-types/{$feeType->id}")
        ->assertStatus(409);

    expect(FeeType::find($feeType->id))->not->toBeNull();
});

test('delete rejects cross-school with 404', function () {
    $otherSchool = School::factory()->create();
    $otherFeeType = FeeType::factory()->forSchool($otherSchool)->create();

    $this->actingAs(makeFeeManager())
        ->deleteJson("/api/v1/fee-types/{$otherFeeType->id}")
        ->assertNotFound();
});

// ─── Audit Log ───────────────────────────────────────────────────────

test('create writes audit log with actor_kind=user', function () {
    $user = makeFeeManager();

    $this->actingAs($user)
        ->postJson('/api/v1/fee-types', [
            'code' => 'spp',
            'label' => 'SPP',
            'default_cadence' => 'monthly',
            'cash_book_category_id' => $this->category->id,
        ])
        ->assertCreated();

    $log = FeeActivityLog::where('subject_type', FeeActivityLog::SUBJECT_FEE_TYPE)
        ->where('action', FeeActivityLog::ACTION_CREATED)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->actor_kind)->toBe(FeeActivityLog::ACTOR_USER)
        ->and($log->actor_id)->toBe($user->id);
});

test('update writes audit log with diff in changes', function () {
    $feeType = FeeType::factory()->forSchool($this->school)->create(['label' => 'Old']);

    $this->actingAs(makeFeeManager())
        ->patchJson("/api/v1/fee-types/{$feeType->id}", ['label' => 'New']);

    $log = FeeActivityLog::where('subject_id', $feeType->id)
        ->where('action', FeeActivityLog::ACTION_UPDATED)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->changes)->toBe(['before' => ['label' => 'Old'], 'after' => ['label' => 'New']]);
});

test('update with no changes does not write audit log (no-op skip)', function () {
    $feeType = FeeType::factory()->forSchool($this->school)->create(['label' => 'Same']);

    $this->actingAs(makeFeeManager())
        ->patchJson("/api/v1/fee-types/{$feeType->id}", ['label' => 'Same']);

    expect(FeeActivityLog::where('subject_id', $feeType->id)->where('action', 'updated')->count())->toBe(0);
});
