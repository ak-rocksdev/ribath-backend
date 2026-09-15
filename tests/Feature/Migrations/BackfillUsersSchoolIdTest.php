<?php

use App\Models\School;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function runBackfillUsersSchoolIdMigration(): void
{
    (require database_path('migrations/2026_09_15_300000_backfill_users_school_id.php'))->up();
}

test('backfill assigns the active school to accounts without school_id', function () {
    $activeSchool = School::factory()->create(['is_active' => true]);
    $inactiveSchool = School::factory()->create(['is_active' => false]);
    $accountWithoutSchool = User::factory()->create(['school_id' => null]);
    $accountOfInactiveSchool = User::factory()->create(['school_id' => $inactiveSchool->id]);

    runBackfillUsersSchoolIdMigration();

    expect($accountWithoutSchool->fresh()->school_id)->toBe($activeSchool->id)
        ->and($accountOfInactiveSchool->fresh()->school_id)->toBe($inactiveSchool->id);
});

test('backfill also fixes soft-deleted accounts so a restore works', function () {
    $activeSchool = School::factory()->create(['is_active' => true]);
    $softDeletedAccount = User::factory()->create(['school_id' => null]);
    $softDeletedAccount->delete();

    runBackfillUsersSchoolIdMigration();

    expect(User::withTrashed()->find($softDeletedAccount->id)->school_id)->toBe($activeSchool->id);
});

test('backfill leaves accounts untouched when there is not exactly one active school', function () {
    School::factory()->count(2)->create(['is_active' => true]);
    $accountWithoutSchool = User::factory()->create(['school_id' => null]);

    runBackfillUsersSchoolIdMigration();

    expect($accountWithoutSchool->fresh()->school_id)->toBeNull();
});

test('backfilled accounts show up in the Akun Pengguna list and summary', function () {
    Permission::firstOrCreate(['name' => 'view-users']);
    School::factory()->create(['is_active' => true]);
    $superAdmin = User::factory()->create(['school_id' => null]);
    $superAdmin->assignRole(Role::firstOrCreate(['name' => 'super_admin']));
    User::factory()->create(['school_id' => null, 'name' => 'Akun Lama']);

    runBackfillUsersSchoolIdMigration();

    $this->actingAs($superAdmin->fresh())->getJson('/api/v1/users')->assertOk()->assertJsonPath('meta.total', 2);
    $this->actingAs($superAdmin->fresh())->getJson('/api/v1/users/summary')->assertOk()->assertJsonPath('data.total', 2);
});
