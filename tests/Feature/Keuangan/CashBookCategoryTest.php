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

test('list includes entries_count for each category', function () {
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
        ->assertJsonPath('data.0.entries_count', 3);
});
