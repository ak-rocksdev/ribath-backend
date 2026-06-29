<?php

use App\Models\AcademicYear;
use App\Models\CashBookCategory;
use App\Models\FeeActivityLog;
use App\Models\FeeSchedule;
use App\Models\FeeType;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentFeeAssignment;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    foreach (['view-student-fees', 'manage-student-fees'] as $perm) {
        Permission::firstOrCreate(['name' => $perm]);
    }

    $this->school = School::factory()->create(['is_active' => true]);
    $this->ay = AcademicYear::factory()->create(['school_id' => $this->school->id, 'is_active' => true]);
    $this->category = CashBookCategory::factory()->for($this->school, 'school')->create();
    $this->spp = FeeType::factory()->forSchool($this->school)->withCadence('monthly')
        ->create(['code' => 'spp', 'cash_book_category_id' => $this->category->id]);
    $this->student = Student::factory()->create(['school_id' => $this->school->id]);
    $this->assignment = StudentFeeAssignment::factory()->forStudent($this->student)->forFeeType($this->spp)
        ->forAcademicYear($this->ay)->create();
});

function makeActivityLogViewer(): User
{
    $user = User::factory()->create();
    $role = Role::firstOrCreate(['name' => 'pengurus_pesantren']);
    $role->syncPermissions(['view-student-fees', 'manage-student-fees']);
    $user->assignRole($role);

    return $user;
}

test('activity log index returns school-scoped logs with actor label', function () {
    $admin = makeActivityLogViewer();
    FeeActivityLog::create([
        'school_id' => $this->school->id,
        'subject_type' => FeeActivityLog::SUBJECT_FEE_SCHEDULE,
        'subject_id' => (string) Str::uuid(),
        'action' => FeeActivityLog::ACTION_CREATED,
        'actor_kind' => FeeActivityLog::ACTOR_USER,
        'actor_id' => $admin->id,
        'changes' => null,
    ]);

    $this->actingAs($admin)
        ->getJson('/api/v1/fee-activity-logs')
        ->assertOk()
        ->assertJsonPath('data.0.subject_type', 'fee_schedule')
        ->assertJsonPath('data.0.actor_label', $admin->name)
        ->assertJsonPath('meta.total', 1);
});

test('activity log renders system actor label for non-user actor_kind', function () {
    $admin = makeActivityLogViewer();
    FeeActivityLog::create([
        'school_id' => $this->school->id,
        'subject_type' => FeeActivityLog::SUBJECT_BILL,
        'subject_id' => (string) Str::uuid(),
        'action' => FeeActivityLog::ACTION_GENERATED,
        'actor_kind' => FeeActivityLog::ACTOR_SCHEDULER,
        'actor_id' => null,
        'changes' => ['period' => '2026-05'],
    ]);

    $this->actingAs($admin)
        ->getJson('/api/v1/fee-activity-logs')
        ->assertOk()
        ->assertJsonPath('data.0.actor_label', 'Sistem (Penjadwal)')
        ->assertJsonPath('data.0.actor', null);
});

test('activity log filters by subject_type and action', function () {
    $admin = makeActivityLogViewer();
    FeeActivityLog::create(['school_id' => $this->school->id, 'subject_type' => 'bill', 'subject_id' => (string) Str::uuid(), 'action' => 'generated', 'actor_kind' => 'scheduler', 'actor_id' => null, 'changes' => null]);
    FeeActivityLog::create(['school_id' => $this->school->id, 'subject_type' => 'payment', 'subject_id' => (string) Str::uuid(), 'action' => 'created', 'actor_kind' => 'user', 'actor_id' => $admin->id, 'changes' => null]);

    $this->actingAs($admin)
        ->getJson('/api/v1/fee-activity-logs?subject_type=payment')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.subject_type', 'payment');

    $this->actingAs($admin)
        ->getJson('/api/v1/fee-activity-logs?action=generated')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.action', 'generated');
});

test('activity log is school-scoped — other school logs hidden', function () {
    $otherSchool = School::factory()->create(['is_active' => false]);
    FeeActivityLog::create(['school_id' => $otherSchool->id, 'subject_type' => 'bill', 'subject_id' => (string) Str::uuid(), 'action' => 'created', 'actor_kind' => 'user', 'actor_id' => null, 'changes' => null]);
    $admin = makeActivityLogViewer();

    $this->actingAs($admin)
        ->getJson('/api/v1/fee-activity-logs')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
});

test('activity log requires view-student-fees permission', function () {
    $user = User::factory()->create();
    $role = Role::firstOrCreate(['name' => 'no_log_access']);
    $role->syncPermissions([]);
    $user->assignRole($role);

    $this->actingAs($user)
        ->getJson('/api/v1/fee-activity-logs')
        ->assertForbidden();
});
