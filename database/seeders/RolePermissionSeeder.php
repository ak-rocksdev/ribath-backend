<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'view-users',
            'create-users',
            'edit-users',
            'delete-users',
            'manage-roles',
            'manage-settings',
            'view-psb-registrations',
            'manage-psb-registrations',
            'view-psb-periods',
            'manage-psb-periods',
        ];

        foreach ($permissions as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName]);
        }

        $superAdmin = Role::firstOrCreate(['name' => 'super_admin']);

        $pengurusPesantren = Role::firstOrCreate(['name' => 'pengurus_pesantren']);
        $pengurusPesantren->syncPermissions([
            'view-users',
            'manage-settings',
            'view-psb-registrations',
            'manage-psb-registrations',
            'view-psb-periods',
            'manage-psb-periods',
        ]);

        $adminUser = User::firstOrCreate(
            ['email' => 'akhabsy110@gmail.com'],
            [
                'name' => 'Abdul Kadir Habsyi',
                'password' => Hash::make('kadir9263606'),
            ]
        );
        $adminUser->assignRole($superAdmin);
    }
}
