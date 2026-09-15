<?php

namespace Database\Seeders;

use App\Models\School;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public const DEFAULT_ADMIN_EMAIL = 'akhabsy110@gmail.com';

    /** The default admin's password outside production when SEED_ADMIN_PASSWORD is not set (see .env.example). */
    public const DEVELOPMENT_ADMIN_PASSWORD = 'password';

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
            'view-registrations',
            'manage-registrations',
            'view-registration-periods',
            'manage-registration-periods',
            'view-students',
            'create-students',
            'edit-students',
            'delete-students',
            'view-teachers',
            'create-teachers',
            'edit-teachers',
            'delete-teachers',
            'manage-class-levels',

            // Academic Year
            'view-academic-years',
            'manage-academic-years',

            // Time Slots
            'view-time-slots',
            'manage-time-slots',

            // Subject Categories & Books
            'view-subject-books',
            'manage-subject-books',
            'view-subject-categories',
            'manage-subject-categories',

            // Teaching Schedule
            'view-schedules',
            'manage-schedules',

            // School Profile
            'manage-school-profile',

            // Cash Book (Buku Kas)
            'view-cashbook',
            'manage-cashbook',

            // Fee Management (SPP & biaya santri)
            'manage-fee-types',
            'manage-fee-schedules',
            'manage-student-fees',
            'view-student-fees',
            'record-payments',

            // Penilaian (grading), Absensi (attendance), Tahfidz (memorization)
            'manage-grading-settings',
            'view-grades',
            'manage-grades',
            'view-attendance',
            'manage-attendance',
            'view-memorization',
            'manage-memorization',

            // "Milik sendiri" counterparts of the pairs above (ADR 0004): data
            // limited to the user's Cakupan Mengajar.
            'view-own-grades',
            'manage-own-grades',
            'view-own-attendance',
            'manage-own-attendance',
            'view-own-memorization',
            'manage-own-memorization',
        ];

        foreach ($permissions as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName]);
        }

        $superAdmin = Role::firstOrCreate(['name' => 'super_admin']);

        $pengurusPesantren = Role::firstOrCreate(['name' => 'pengurus_pesantren']);
        $pengurusPesantren->syncPermissions([
            'view-users',
            'manage-settings',
            'view-registrations',
            'manage-registrations',
            'view-registration-periods',
            'manage-registration-periods',
            'view-students',
            'create-students',
            'edit-students',
            'delete-students',
            'view-teachers',
            'create-teachers',
            'edit-teachers',
            'delete-teachers',
            'manage-class-levels',
            'view-academic-years',
            'manage-academic-years',
            'view-time-slots',
            'manage-time-slots',
            'view-subject-books',
            'manage-subject-books',
            'view-subject-categories',
            'manage-subject-categories',
            'view-schedules',
            'manage-schedules',
            'manage-school-profile',
            'view-cashbook',
            'manage-cashbook',
            'manage-fee-types',
            'manage-fee-schedules',
            'manage-student-fees',
            'view-student-fees',
            'record-payments',
            'manage-grading-settings',
            'view-grades',
            'manage-grades',
            'view-attendance',
            'manage-attendance',
            'view-memorization',
            'manage-memorization',
        ]);

        // Akun Ustadz: the role "Beri Akses" gives (TeacherService::grantAccess).
        $ustadz = Role::firstOrCreate(['name' => 'ustadz']);
        $ustadz->syncPermissions([
            'view-own-grades',
            'manage-own-grades',
            'view-own-attendance',
            'manage-own-attendance',
            'view-own-memorization',
            'manage-own-memorization',
            'view-academic-years',
        ]);

        $this->seedDefaultAdmin($superAdmin);
    }

    /**
     * The default super_admin account. Its password comes from
     * SEED_ADMIN_PASSWORD (config app.seed_admin_password) and is used only
     * when the account is created — an existing admin keeps his password
     * and only gets the role again. In production without it, no admin is
     * created (a warning says so); elsewhere the documented development
     * password is used. Never required to change the password
     * (must_change_password stays false).
     */
    private function seedDefaultAdmin(Role $superAdmin): void
    {
        $adminUser = User::where('email', self::DEFAULT_ADMIN_EMAIL)->first();

        if ($adminUser === null) {
            $seedAdminPassword = (string) config('app.seed_admin_password');

            if ($seedAdminPassword === '' && app()->environment('production')) {
                $warning = 'SEED_ADMIN_PASSWORD is not set: the default super_admin account was not created.';
                $this->command?->warn($warning);
                Log::warning($warning);

                return;
            }

            // Joins the active school so the Akun Pengguna list (scoped to that
            // school) shows it; a fresh database may not have a school yet.
            $activeSchool = School::where('is_active', true)->first();

            $adminUser = User::create([
                'email' => self::DEFAULT_ADMIN_EMAIL,
                'school_id' => $activeSchool?->id,
                'name' => 'Abdul Kadir Habsyi',
                'password' => Hash::make($seedAdminPassword !== '' ? $seedAdminPassword : self::DEVELOPMENT_ADMIN_PASSWORD),
            ]);
        }

        $adminUser->assignRole($superAdmin);
    }
}
