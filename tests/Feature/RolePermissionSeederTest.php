<?php

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('seeder creates roles and permissions', function () {
    $this->seed(\Database\Seeders\RolePermissionSeeder::class);

    expect(Role::where('name', 'super_admin')->exists())->toBeTrue()
        ->and(Role::where('name', 'pengurus_pesantren')->exists())->toBeTrue()
        ->and(Role::count())->toBe(2);

    expect(Permission::where('name', 'view-users')->exists())->toBeTrue()
        ->and(Permission::where('name', 'create-users')->exists())->toBeTrue()
        ->and(Permission::where('name', 'edit-users')->exists())->toBeTrue()
        ->and(Permission::where('name', 'delete-users')->exists())->toBeTrue()
        ->and(Permission::where('name', 'manage-roles')->exists())->toBeTrue()
        ->and(Permission::where('name', 'manage-settings')->exists())->toBeTrue()
        ->and(Permission::where('name', 'view-registrations')->exists())->toBeTrue()
        ->and(Permission::where('name', 'manage-registrations')->exists())->toBeTrue()
        ->and(Permission::where('name', 'view-registration-periods')->exists())->toBeTrue()
        ->and(Permission::where('name', 'manage-registration-periods')->exists())->toBeTrue()
        ->and(Permission::where('name', 'view-students')->exists())->toBeTrue()
        ->and(Permission::where('name', 'create-students')->exists())->toBeTrue()
        ->and(Permission::where('name', 'edit-students')->exists())->toBeTrue()
        ->and(Permission::where('name', 'delete-students')->exists())->toBeTrue();
});

test('seeder assigns permissions to pengurus_pesantren', function () {
    $this->seed(\Database\Seeders\RolePermissionSeeder::class);

    $pengurusPesantren = Role::findByName('pengurus_pesantren');

    expect($pengurusPesantren->hasPermissionTo('view-users'))->toBeTrue()
        ->and($pengurusPesantren->hasPermissionTo('manage-settings'))->toBeTrue()
        ->and($pengurusPesantren->hasPermissionTo('view-registrations'))->toBeTrue()
        ->and($pengurusPesantren->hasPermissionTo('manage-registrations'))->toBeTrue()
        ->and($pengurusPesantren->hasPermissionTo('view-registration-periods'))->toBeTrue()
        ->and($pengurusPesantren->hasPermissionTo('manage-registration-periods'))->toBeTrue()
        ->and($pengurusPesantren->hasPermissionTo('view-students'))->toBeTrue()
        ->and($pengurusPesantren->hasPermissionTo('create-students'))->toBeTrue()
        ->and($pengurusPesantren->hasPermissionTo('edit-students'))->toBeTrue()
        ->and($pengurusPesantren->hasPermissionTo('delete-students'))->toBeTrue()
        ->and($pengurusPesantren->hasPermissionTo('delete-users'))->toBeFalse();
});

test('seeder creates admin user with super_admin role', function () {
    $this->seed(\Database\Seeders\RolePermissionSeeder::class);

    $adminUser = User::where('email', 'akhabsy110@gmail.com')->first();

    expect($adminUser)->not->toBeNull()
        ->and($adminUser->name)->toBe('Abdul Kadir Habsyi')
        ->and($adminUser->hasRole('super_admin'))->toBeTrue();
});

test('seeder is idempotent', function () {
    $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    $this->seed(\Database\Seeders\RolePermissionSeeder::class);

    expect(Role::count())->toBe(2)
        ->and(User::where('email', 'akhabsy110@gmail.com')->count())->toBe(1);
});
