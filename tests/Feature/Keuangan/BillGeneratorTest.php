<?php

use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\CashBookCategory;
use App\Models\ClassLevel;
use App\Models\FeeActivityLog;
use App\Models\FeeSchedule;
use App\Models\FeeType;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentFeeAssignment;
use App\Models\User;
use App\Services\Keuangan\BillGeneratorService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    foreach (['view-student-fees', 'manage-student-fees', 'record-payments'] as $perm) {
        Permission::firstOrCreate(['name' => $perm]);
    }

    $this->school = School::factory()->create(['is_active' => true]);
    $this->ay = AcademicYear::factory()->create(['school_id' => $this->school->id, 'is_active' => true]);
    ClassLevel::factory()->create(['slug' => 'ibtida_1']);
    $this->category = CashBookCategory::factory()->for($this->school, 'school')->create();
    $this->spp = FeeType::factory()->forSchool($this->school)->withCadence('monthly')
        ->create(['code' => 'spp', 'cash_book_category_id' => $this->category->id]);
    FeeSchedule::factory()->forSchool($this->school)->forAcademicYear($this->ay)->forFeeType($this->spp)
        ->create(['amount' => 500000]);

    $this->generator = app(BillGeneratorService::class);
});

function makeBillManagerUser(): User
{
    $user = User::factory()->create();
    $role = Role::firstOrCreate(['name' => 'pengurus_pesantren']);
    $role->syncPermissions(['view-student-fees', 'manage-student-fees', 'record-payments']);
    $user->assignRole($role);

    return $user;
}

test('generator creates 1 bill per active student per matching cadence assignment', function () {
    // 3 active santri with SPP monthly assignment
    $students = Student::factory()->count(3)->create(['school_id' => $this->school->id, 'status' => 'active']);
    foreach ($students as $s) {
        StudentFeeAssignment::factory()->forStudent($s)->forFeeType($this->spp)->forAcademicYear($this->ay)
            ->create(['locked_amount' => 500000, 'cadence' => 'monthly']);
    }

    $stats = $this->generator->generate(now('Asia/Jakarta')->format('Y-m'));

    expect($stats['bills_created'])->toBe(3)
        ->and(Bill::count())->toBe(3)
        ->and(Bill::first()->expected_amount)->toBe(500000)
        ->and(Bill::first()->status)->toBe(Bill::STATUS_PENDING);
});

test('generator is idempotent — re-run produces zero duplicates', function () {
    $student = Student::factory()->create(['school_id' => $this->school->id, 'status' => 'active']);
    StudentFeeAssignment::factory()->forStudent($student)->forFeeType($this->spp)->forAcademicYear($this->ay)
        ->create(['locked_amount' => 500000, 'cadence' => 'monthly']);

    $first = $this->generator->generate(now('Asia/Jakarta')->format('Y-m'));
    $second = $this->generator->generate(now('Asia/Jakarta')->format('Y-m'));

    expect($first['bills_created'])->toBe(1)
        ->and($second['bills_created'])->toBe(0)
        ->and($second['bills_skipped_existing'])->toBe(1)
        ->and(Bill::count())->toBe(1);
});

test('generator skips alumni and graduated students', function () {
    $active = Student::factory()->create(['school_id' => $this->school->id, 'status' => 'active']);
    $graduated = Student::factory()->create(['school_id' => $this->school->id, 'status' => 'graduated']);
    $withdrawn = Student::factory()->create(['school_id' => $this->school->id, 'status' => 'withdrawn']);

    foreach ([$active, $graduated, $withdrawn] as $s) {
        StudentFeeAssignment::factory()->forStudent($s)->forFeeType($this->spp)->forAcademicYear($this->ay)
            ->create(['cadence' => 'monthly']);
    }

    $this->generator->generate(now('Asia/Jakarta')->format('Y-m'));

    expect(Bill::count())->toBe(1)
        ->and(Bill::first()->student_id)->toBe($active->id);
});

test('cadence filter restricts which assignments get bills', function () {
    $yearly = FeeType::factory()->forSchool($this->school)->withCadence('yearly')
        ->create(['code' => 'gedung', 'cash_book_category_id' => $this->category->id]);
    FeeSchedule::factory()->forSchool($this->school)->forAcademicYear($this->ay)->forFeeType($yearly)
        ->create(['amount' => 5000000]);

    $student = Student::factory()->create(['school_id' => $this->school->id]);
    StudentFeeAssignment::factory()->forStudent($student)->forFeeType($this->spp)->forAcademicYear($this->ay)
        ->create(['cadence' => 'monthly']);
    StudentFeeAssignment::factory()->forStudent($student)->forFeeType($yearly)->forAcademicYear($this->ay)
        ->create(['cadence' => 'yearly']);

    $stats = $this->generator->generate(now('Asia/Jakarta')->format('Y-m'), 'monthly');

    expect($stats['bills_created'])->toBe(1)
        ->and(Bill::first()->cadence_at_generation)->toBe('monthly');
});

test('zero expected_amount triggers waived status (FR-022 + Clarifications Q4)', function () {
    $student = Student::factory()->create(['school_id' => $this->school->id]);
    StudentFeeAssignment::factory()->forStudent($student)->forFeeType($this->spp)->forAcademicYear($this->ay)
        ->create(['cadence' => 'monthly', 'locked_amount' => 0]);

    $this->generator->generate(now('Asia/Jakarta')->format('Y-m'));

    expect(Bill::first()->status)->toBe(Bill::STATUS_WAIVED)
        ->and((int) Bill::first()->expected_amount)->toBe(0);
});

test('generator writes audit log row with action=generated and actor_kind from caller', function () {
    Student::factory()->create(['school_id' => $this->school->id]);

    $this->generator->generate(now('Asia/Jakarta')->format('Y-m'), 'all', FeeActivityLog::ACTOR_SCHEDULER);

    $log = FeeActivityLog::query()
        ->where('action', FeeActivityLog::ACTION_GENERATED)
        ->where('subject_type', FeeActivityLog::SUBJECT_BILL)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->actor_kind)->toBe(FeeActivityLog::ACTOR_SCHEDULER)
        ->and($log->actor_id)->toBeNull()
        ->and($log->changes['cadence_filter'])->toBe('all');
});

test('arrears-summary endpoint returns 3 buckets with student_count + total_remaining without uuid sum() error', function () {
    // Reproduce the pgsql bug where chained count/sum on the same builder
    // leaked DISTINCT into a SUM(uuid) call. Now uses selectRaw single query.
    $student = Student::factory()->create(['school_id' => $this->school->id]);
    $assignment = StudentFeeAssignment::factory()->forStudent($student)->forFeeType($this->spp)
        ->forAcademicYear($this->ay)->create(['locked_amount' => 500000, 'cadence' => 'monthly']);
    // Overdue bill from previous period
    Bill::factory()->forAssignment($assignment)->pending()
        ->create([
            'billing_period_start' => now('Asia/Jakarta')->subMonths(2)->startOfMonth()->toDateString(),
            'billing_period_end' => now('Asia/Jakarta')->subMonths(2)->endOfMonth()->toDateString(),
            'due_date' => now('Asia/Jakarta')->subMonths(2)->startOfMonth()->addDays(9)->toDateString(),
        ]);
    $admin = makeBillManagerUser();

    $this->actingAs($admin)
        ->getJson('/api/v1/bills/arrears-summary')
        ->assertOk()
        ->assertJsonStructure(['data' => ['overdue_1_month', 'overdue_2_3_months', 'overdue_more_than_3_months']])
        ->assertJsonPath('data.overdue_2_3_months.student_count', 1)
        ->assertJsonPath('data.overdue_2_3_months.total_remaining', 500000);
});

test('manual generate via POST endpoint requires manage-student-fees and returns stats', function () {
    Student::factory()->create(['school_id' => $this->school->id]);
    StudentFeeAssignment::factory()->forFeeType($this->spp)->forAcademicYear($this->ay)
        ->forStudent(Student::factory()->create(['school_id' => $this->school->id]))
        ->create(['cadence' => 'monthly']);
    $admin = makeBillManagerUser();

    $this->actingAs($admin)
        ->postJson('/api/v1/bills/generate', ['period' => now('Asia/Jakarta')->format('Y-m')])
        ->assertCreated()
        ->assertJsonPath('data.bills_created', 1);
});

test('manual generate without permission returns 403', function () {
    $user = User::factory()->create();
    $role = Role::firstOrCreate(['name' => 'no_perms']);
    $role->syncPermissions([]);
    $user->assignRole($role);

    $this->actingAs($user)
        ->postJson('/api/v1/bills/generate', [])
        ->assertForbidden();
});
