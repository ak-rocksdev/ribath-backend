<?php

use App\Models\CashBookCategory;
use App\Models\CashBookEntry;
use App\Models\School;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::firstOrCreate(['name' => 'view-cashbook']);
    Permission::firstOrCreate(['name' => 'manage-cashbook']);

    $this->school = School::factory()->create(['is_active' => true]);
});

function makeCashBookUser(): User
{
    $user = User::factory()->create();
    $role = Role::firstOrCreate(['name' => 'pengurus_pesantren']);
    $role->syncPermissions(['view-cashbook', 'manage-cashbook']);
    $user->assignRole($role);

    return $user;
}

test('unauthenticated user cannot list categories', function () {
    $this->getJson('/api/v1/cash-book-categories')->assertUnauthorized();
});

test('lists only active categories by default', function () {
    $user = makeCashBookUser();

    CashBookCategory::factory()
        ->for($this->school, 'school')
        ->create(['is_active' => true]);
    CashBookCategory::factory()
        ->for($this->school, 'school')
        ->inactive()
        ->create();

    $this->actingAs($user)
        ->getJson('/api/v1/cash-book-categories')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('includes inactive categories when is_active=false is passed', function () {
    $user = makeCashBookUser();

    CashBookCategory::factory()
        ->for($this->school, 'school')
        ->create(['is_active' => true]);
    CashBookCategory::factory()
        ->for($this->school, 'school')
        ->inactive()
        ->create();

    $this->actingAs($user)
        ->getJson('/api/v1/cash-book-categories?is_active=false')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('list scopes to active school only', function () {
    $user = makeCashBookUser();
    CashBookCategory::factory()->for($this->school, 'school')->count(2)->create();

    $otherSchool = School::factory()->create(['is_active' => false]);
    CashBookCategory::factory()->for($otherSchool, 'school')->count(3)->create();

    $this->actingAs($user)
        ->getJson('/api/v1/cash-book-categories')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

// ─── Create (US3) ───────────────────────────────────────────────────

test('manager can create category', function () {
    $user = makeCashBookUser();

    $this->actingAs($user)->postJson('/api/v1/cash-book-categories', [
        'name' => 'Donasi Insidental',
    ])->assertCreated()
        ->assertJsonPath('data.name', 'Donasi Insidental')
        ->assertJsonPath('data.is_system', false)
        ->assertJsonPath('data.is_active', true);

    expect(CashBookCategory::where('name', 'Donasi Insidental')->exists())->toBeTrue();
});

test('create rejects duplicate name per school', function () {
    $user = makeCashBookUser();
    CashBookCategory::factory()->for($this->school, 'school')->create(['name' => 'SPP']);

    $this->actingAs($user)->postJson('/api/v1/cash-book-categories', [
        'name' => 'SPP',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('create allows same name in different school', function () {
    $user = makeCashBookUser();
    $otherSchool = School::factory()->create(['is_active' => false]);
    CashBookCategory::factory()->for($otherSchool, 'school')->create(['name' => 'SPP']);

    $this->actingAs($user)->postJson('/api/v1/cash-book-categories', [
        'name' => 'SPP',
    ])->assertCreated();
});

// ─── Update (US3) ───────────────────────────────────────────────────

test('manager can rename category', function () {
    $user = makeCashBookUser();
    $category = CashBookCategory::factory()
        ->for($this->school, 'school')
        ->create(['name' => 'Listrik']);

    $this->actingAs($user)->patchJson("/api/v1/cash-book-categories/{$category->id}", [
        'name' => 'Listrik PLN',
    ])->assertOk()
        ->assertJsonPath('data.name', 'Listrik PLN');
});

test('manager can toggle is_active', function () {
    $user = makeCashBookUser();
    $category = CashBookCategory::factory()->for($this->school, 'school')->create(['is_active' => true]);

    $this->actingAs($user)->patchJson("/api/v1/cash-book-categories/{$category->id}", [
        'is_active' => false,
    ])->assertOk()
        ->assertJsonPath('data.is_active', false);
});

test('update rejects renaming a system category', function () {
    $user = makeCashBookUser();
    $system = CashBookCategory::factory()
        ->for($this->school, 'school')
        ->saldoAwal()
        ->create();

    $this->actingAs($user)->patchJson("/api/v1/cash-book-categories/{$system->id}", [
        'name' => 'Different Name',
    ])->assertForbidden();

    expect($system->fresh()->name)->toBe(CashBookCategory::SYSTEM_NAME_SALDO_AWAL);
});

test('update rejects deactivating a system category', function () {
    $user = makeCashBookUser();
    $system = CashBookCategory::factory()
        ->for($this->school, 'school')
        ->saldoAwal()
        ->create();

    $this->actingAs($user)->patchJson("/api/v1/cash-book-categories/{$system->id}", [
        'is_active' => false,
    ])->assertForbidden();

    expect($system->fresh()->is_active)->toBeTrue();
});

// ─── Delete (US3) ───────────────────────────────────────────────────

test('manager can delete empty category', function () {
    $user = makeCashBookUser();
    $category = CashBookCategory::factory()->for($this->school, 'school')->create();

    $this->actingAs($user)
        ->deleteJson("/api/v1/cash-book-categories/{$category->id}")
        ->assertOk();

    expect(CashBookCategory::find($category->id))->toBeNull();
});

test('delete rejects system category', function () {
    $user = makeCashBookUser();
    $system = CashBookCategory::factory()
        ->for($this->school, 'school')
        ->saldoAwal()
        ->create();

    $this->actingAs($user)
        ->deleteJson("/api/v1/cash-book-categories/{$system->id}")
        ->assertForbidden();

    expect(CashBookCategory::find($system->id))->not->toBeNull();
});

test('delete rejects category with entries, returns 409 + entries_count', function () {
    $user = makeCashBookUser();
    $category = CashBookCategory::factory()->for($this->school, 'school')->create();
    \App\Models\CashBookEntry::factory()
        ->count(3)
        ->for($this->school, 'school')
        ->for($category, 'category')
        ->create(['created_by' => $user->id]);

    $this->actingAs($user)
        ->deleteJson("/api/v1/cash-book-categories/{$category->id}")
        ->assertStatus(409)
        ->assertJsonPath('errors.entries_count', 3);

    expect(CashBookCategory::find($category->id))->not->toBeNull();
});

// ─── Permission gate ─────────────────────────────────────────────────

test('viewer cannot create/update/delete categories', function () {
    $viewer = \App\Models\User::factory()->create();
    $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'viewer-only']);
    $role->syncPermissions(['view-cashbook']);
    $viewer->assignRole($role);

    $category = CashBookCategory::factory()->for($this->school, 'school')->create();

    $this->actingAs($viewer)
        ->postJson('/api/v1/cash-book-categories', ['name' => 'New'])
        ->assertForbidden();

    $this->actingAs($viewer)
        ->patchJson("/api/v1/cash-book-categories/{$category->id}", ['name' => 'X'])
        ->assertForbidden();

    $this->actingAs($viewer)
        ->deleteJson("/api/v1/cash-book-categories/{$category->id}")
        ->assertForbidden();
});

// ─── Original list tests below ──────────────────────────────────────

test('list includes entries_count when with_entries_count=true', function () {
    $user = makeCashBookUser();
    $category = CashBookCategory::factory()->for($this->school, 'school')->create();

    CashBookEntry::factory()
        ->count(3)
        ->for($this->school, 'school')
        ->for($category, 'category')
        ->create(['created_by' => $user->id]);

    $this->actingAs($user)
        ->getJson('/api/v1/cash-book-categories?with_entries_count=true')
        ->assertOk()
        ->assertJsonPath('data.0.entries_count', 3);
});

test('list omits entries_count by default to save a query', function () {
    $user = makeCashBookUser();
    $category = CashBookCategory::factory()->for($this->school, 'school')->create();

    CashBookEntry::factory()
        ->count(3)
        ->for($this->school, 'school')
        ->for($category, 'category')
        ->create(['created_by' => $user->id]);

    $this->actingAs($user)
        ->getJson('/api/v1/cash-book-categories')
        ->assertOk()
        ->assertJsonMissingPath('data.0.entries_count');
});
