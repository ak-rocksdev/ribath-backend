<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

test('super_admin bypasses all permission checks', function () {
    $role = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);

    expect($user->can('any-random-permission'))->toBeTrue();
});

test('non super_admin does not bypass permission checks', function () {
    $role = Role::create(['name' => 'pengurus_pesantren', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);

    expect($user->can('any-random-permission'))->toBeFalse();
});
