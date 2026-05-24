<?php

use Spatie\Permission\Models\Permission;

/**
 * Permission matrix scaffold for fee management endpoints.
 *
 * RefreshDatabase is applied globally via tests/Pest.php for Feature tests.
 *
 * Each user story phase appends its own permission test cases here as
 * controllers + routes come online:
 *   - US1 (master): fee-types, fee-schedules — appended by T029, T030
 *   - US2 (snapshot): students/{s}/fee-assignments + fees/unassigned-students
 *   - US3 (operasional): bills, student-payments
 *   - US4 (exception): students/{s}/fee-assignments/{a}/exceptions
 *
 * Pattern per added case: hit endpoint with user lacking the permission and
 * assert Spatie middleware blocks with 403. `super_admin` always succeeds
 * via the Gate::before wildcard in AppServiceProvider (verified separately).
 *
 * This file currently asserts the 5 fee permissions exist in the seeder so
 * downstream test rows can rely on them.
 */

beforeEach(function () {
    $this->seed(\Database\Seeders\RolePermissionSeeder::class);
});

it('seeds all 5 fee permissions for downstream permission tests to rely on', function () {
    foreach ([
        'manage-fee-types',
        'manage-fee-schedules',
        'manage-student-fees',
        'view-student-fees',
        'record-payments',
    ] as $permission) {
        expect(Permission::where('name', $permission)->exists())->toBeTrue(
            "Permission [$permission] missing from RolePermissionSeeder"
        );
    }
});
