<?php

use App\Models\AcademicYear;
use App\Models\CashBookCategory;
use App\Models\FeeType;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentFeeAssignment;
use App\Models\StudentFeeException;
use App\Models\User;
use App\Services\Keuangan\EffectiveAmountCalculator;
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
    $this->assignment = StudentFeeAssignment::factory()
        ->forStudent($this->student)->forFeeType($this->spp)->forAcademicYear($this->ay)
        ->create(['locked_amount' => 500000, 'cadence' => 'monthly']);
});

function makeFeeExceptionManager(): User
{
    $user = User::factory()->create();
    $role = Role::firstOrCreate(['name' => 'pengurus_pesantren']);
    $role->syncPermissions(['view-student-fees', 'manage-student-fees']);
    $user->assignRole($role);

    return $user;
}

function makeNoExceptionUser(): User
{
    $user = User::factory()->create();
    $role = Role::firstOrCreate(['name' => 'no_exc_role']);
    $role->syncPermissions([]);
    $user->assignRole($role);

    return $user;
}

function exceptionUrl(string $studentId, string $assignmentId, ?string $exceptionId = null): string
{
    $base = "/api/v1/students/{$studentId}/fee-assignments/{$assignmentId}/exceptions";

    return $exceptionId ? "{$base}/{$exceptionId}" : $base;
}

// ──────────────────────────────────────────────────────
// Create
// ──────────────────────────────────────────────────────

test('create full_waiver exception happy path', function () {
    $admin = makeFeeExceptionManager();

    $this->actingAs($admin)
        ->postJson(exceptionUrl($this->student->id, $this->assignment->id), [
            'kind' => 'full_waiver',
            'reason' => 'Yatim, by KH Anu',
            'effective_from' => now('Asia/Jakarta')->startOfMonth()->toDateString(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'full_waiver')
        ->assertJsonPath('data.discount_amount', null);

    expect(StudentFeeException::count())->toBe(1);
});

test('create partial_nominal exception happy path', function () {
    $admin = makeFeeExceptionManager();

    $this->actingAs($admin)
        ->postJson(exceptionUrl($this->student->id, $this->assignment->id), [
            'kind' => 'partial_nominal',
            'discount_amount' => 200000,
            'reason' => 'Potongan dhuafa',
            'effective_from' => now('Asia/Jakarta')->startOfMonth()->toDateString(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.discount_amount', 200000);
});

test('partial_nominal requires discount_amount', function () {
    $admin = makeFeeExceptionManager();

    $this->actingAs($admin)
        ->postJson(exceptionUrl($this->student->id, $this->assignment->id), [
            'kind' => 'partial_nominal',
            'reason' => 'tanpa nominal',
            'effective_from' => now('Asia/Jakarta')->toDateString(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['discount_amount']);
});

// ──────────────────────────────────────────────────────
// FR-019 over-discount cap
// ──────────────────────────────────────────────────────

test('over-discount rejected when sum exceeds locked (FR-019)', function () {
    StudentFeeException::factory()->forAssignment($this->assignment)->partialDiscount(400000)->create();
    $admin = makeFeeExceptionManager();

    // 400000 existing + 200000 new = 600000 > 500000 locked
    $this->actingAs($admin)
        ->postJson(exceptionUrl($this->student->id, $this->assignment->id), [
            'kind' => 'partial_nominal',
            'discount_amount' => 200000,
            'reason' => 'over',
            'effective_from' => now('Asia/Jakarta')->toDateString(),
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Total potongan tidak boleh melebihi tarif locked.');
});

test('stacked partials within cap are accepted', function () {
    StudentFeeException::factory()->forAssignment($this->assignment)->partialDiscount(200000)->create();
    $admin = makeFeeExceptionManager();

    // 200000 + 250000 = 450000 <= 500000
    $this->actingAs($admin)
        ->postJson(exceptionUrl($this->student->id, $this->assignment->id), [
            'kind' => 'partial_nominal',
            'discount_amount' => 250000,
            'reason' => 'second potongan',
            'effective_from' => now('Asia/Jakarta')->toDateString(),
        ])
        ->assertCreated();

    expect($this->assignment->exceptions()->count())->toBe(2);
});

// ──────────────────────────────────────────────────────
// FR-020 full_waiver dedup
// ──────────────────────────────────────────────────────

test('second full_waiver rejected when one already active (FR-020)', function () {
    StudentFeeException::factory()->forAssignment($this->assignment)->fullWaiver()->create();
    $admin = makeFeeExceptionManager();

    $this->actingAs($admin)
        ->postJson(exceptionUrl($this->student->id, $this->assignment->id), [
            'kind' => 'full_waiver',
            'reason' => 'duplicate',
            'effective_from' => now('Asia/Jakarta')->toDateString(),
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Sudah ada beasiswa penuh aktif. Hapus dulu untuk menambah baru.');
});

test('expired full_waiver does NOT block a new full_waiver (effective-date aware FR-020)', function () {
    StudentFeeException::factory()->forAssignment($this->assignment)->fullWaiver()
        ->effectiveFrom(now('Asia/Jakarta')->subYear()->toDateString())
        ->effectiveUntil(now('Asia/Jakarta')->subMonths(6)->toDateString())->create();
    $admin = makeFeeExceptionManager();

    $this->actingAs($admin)
        ->postJson(exceptionUrl($this->student->id, $this->assignment->id), [
            'kind' => 'full_waiver',
            'reason' => 'tahun ajaran baru',
            'effective_from' => now('Asia/Jakarta')->toDateString(),
        ])
        ->assertCreated();
});

test('expired partial does NOT count toward the discount cap (effective-date aware FR-019)', function () {
    StudentFeeException::factory()->forAssignment($this->assignment)->partialDiscount(400000)
        ->effectiveFrom(now('Asia/Jakarta')->subYear()->toDateString())
        ->effectiveUntil(now('Asia/Jakarta')->subMonths(6)->toDateString())->create();
    $admin = makeFeeExceptionManager();

    $this->actingAs($admin)
        ->postJson(exceptionUrl($this->student->id, $this->assignment->id), [
            'kind' => 'partial_nominal',
            'discount_amount' => 400000,
            'reason' => 'potongan baru',
            'effective_from' => now('Asia/Jakarta')->toDateString(),
        ])
        ->assertCreated();
});

test('cross-tenant exception update/delete returns 404', function () {
    $otherSchool = School::factory()->create(['is_active' => false]);
    $otherStudent = Student::factory()->create(['school_id' => $otherSchool->id]);
    $otherAssignment = StudentFeeAssignment::factory()->forStudent($otherStudent)->forFeeType($this->spp)
        ->forAcademicYear($this->ay)->create();
    $otherException = StudentFeeException::factory()->forAssignment($otherAssignment)->partialDiscount(100000)->create();
    $admin = makeFeeExceptionManager();

    $this->actingAs($admin)
        ->patchJson(exceptionUrl($otherStudent->id, $otherAssignment->id, $otherException->id), ['discount_amount' => 50000])
        ->assertNotFound();

    $this->actingAs($admin)
        ->deleteJson(exceptionUrl($otherStudent->id, $otherAssignment->id, $otherException->id))
        ->assertNotFound();
});

test('update rejects changing kind (immutable)', function () {
    $exception = StudentFeeException::factory()->forAssignment($this->assignment)->partialDiscount(200000)->create();
    $admin = makeFeeExceptionManager();

    $this->actingAs($admin)
        ->patchJson(exceptionUrl($this->student->id, $this->assignment->id, $exception->id), [
            'kind' => 'full_waiver',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['kind']);
});

test('full_waiver rejects discount_amount in payload', function () {
    $admin = makeFeeExceptionManager();

    $this->actingAs($admin)
        ->postJson(exceptionUrl($this->student->id, $this->assignment->id), [
            'kind' => 'full_waiver',
            'discount_amount' => 50000,
            'reason' => 'salah isi',
            'effective_from' => now('Asia/Jakarta')->toDateString(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['discount_amount']);
});

// ──────────────────────────────────────────────────────
// EffectiveAmountCalculator integration
// ──────────────────────────────────────────────────────

test('effective amount = 0 with active full_waiver', function () {
    StudentFeeException::factory()->forAssignment($this->assignment)->fullWaiver()
        ->effectiveFrom(now('Asia/Jakarta')->startOfMonth()->toDateString())->create();

    $calc = app(EffectiveAmountCalculator::class);
    expect($calc->compute($this->assignment->fresh(), now('Asia/Jakarta')))->toBe(0);
});

test('effective amount subtracts stacked partials capped at 0', function () {
    StudentFeeException::factory()->forAssignment($this->assignment)->partialDiscount(200000)
        ->effectiveFrom(now('Asia/Jakarta')->startOfMonth()->toDateString())->create();
    StudentFeeException::factory()->forAssignment($this->assignment)->partialDiscount(150000)
        ->effectiveFrom(now('Asia/Jakarta')->startOfMonth()->toDateString())->create();

    $calc = app(EffectiveAmountCalculator::class);
    expect($calc->compute($this->assignment->fresh(), now('Asia/Jakarta')))->toBe(150000); // 500k - 350k
});

test('future-dated exception not applied before effective_from', function () {
    StudentFeeException::factory()->forAssignment($this->assignment)->partialDiscount(200000)
        ->effectiveFrom(now('Asia/Jakarta')->addMonth()->toDateString())->create();

    $calc = app(EffectiveAmountCalculator::class);
    // Today is before effective_from → no discount applied
    expect($calc->compute($this->assignment->fresh(), now('Asia/Jakarta')))->toBe(500000);
});

test('expired exception not applied after effective_until', function () {
    StudentFeeException::factory()->forAssignment($this->assignment)->partialDiscount(200000)
        ->effectiveFrom(now('Asia/Jakarta')->subMonths(3)->toDateString())
        ->effectiveUntil(now('Asia/Jakarta')->subMonth()->toDateString())->create();

    $calc = app(EffectiveAmountCalculator::class);
    expect($calc->compute($this->assignment->fresh(), now('Asia/Jakarta')))->toBe(500000);
});

test('soft-deleted exception no longer affects effective amount', function () {
    $exception = StudentFeeException::factory()->forAssignment($this->assignment)->partialDiscount(200000)
        ->effectiveFrom(now('Asia/Jakarta')->startOfMonth()->toDateString())->create();

    $calc = app(EffectiveAmountCalculator::class);
    expect($calc->compute($this->assignment->fresh(), now('Asia/Jakarta')))->toBe(300000);

    $exception->delete();
    expect($calc->compute($this->assignment->fresh(), now('Asia/Jakarta')))->toBe(500000);
});

// ──────────────────────────────────────────────────────
// Update + delete + multi-tenant + permission
// ──────────────────────────────────────────────────────

test('update partial discount re-runs cap validation excluding self', function () {
    $exception = StudentFeeException::factory()->forAssignment($this->assignment)->partialDiscount(200000)->create();
    $admin = makeFeeExceptionManager();

    // Update same exception to 450000 — should pass (excludes self from sum)
    $this->actingAs($admin)
        ->patchJson(exceptionUrl($this->student->id, $this->assignment->id, $exception->id), [
            'discount_amount' => 450000,
        ])
        ->assertOk()
        ->assertJsonPath('data.discount_amount', 450000);
});

test('manager can soft-delete exception', function () {
    $exception = StudentFeeException::factory()->forAssignment($this->assignment)->partialDiscount(200000)->create();
    $admin = makeFeeExceptionManager();

    $this->actingAs($admin)
        ->deleteJson(exceptionUrl($this->student->id, $this->assignment->id, $exception->id))
        ->assertOk();

    expect(StudentFeeException::count())->toBe(0)
        ->and(StudentFeeException::withTrashed()->count())->toBe(1);
});

test('cross-tenant exception create returns 404', function () {
    $otherSchool = School::factory()->create(['is_active' => false]);
    $otherStudent = Student::factory()->create(['school_id' => $otherSchool->id]);
    $otherAssignment = StudentFeeAssignment::factory()->forStudent($otherStudent)->forFeeType($this->spp)
        ->forAcademicYear($this->ay)->create();
    $admin = makeFeeExceptionManager();

    $this->actingAs($admin)
        ->postJson(exceptionUrl($otherStudent->id, $otherAssignment->id), [
            'kind' => 'full_waiver',
            'reason' => 'x',
            'effective_from' => now('Asia/Jakarta')->toDateString(),
        ])
        ->assertNotFound();
});

test('user without manage-student-fees cannot create exception', function () {
    $user = makeNoExceptionUser();

    $this->actingAs($user)
        ->postJson(exceptionUrl($this->student->id, $this->assignment->id), [
            'kind' => 'full_waiver',
            'reason' => 'x',
            'effective_from' => now('Asia/Jakarta')->toDateString(),
        ])
        ->assertForbidden();
});

test('exception write audit log with subject_type=exception', function () {
    $admin = makeFeeExceptionManager();

    $this->actingAs($admin)
        ->postJson(exceptionUrl($this->student->id, $this->assignment->id), [
            'kind' => 'full_waiver',
            'reason' => 'audit check',
            'effective_from' => now('Asia/Jakarta')->toDateString(),
        ])
        ->assertCreated();

    $log = \App\Models\FeeActivityLog::where('subject_type', 'exception')->first();
    expect($log)->not->toBeNull()
        ->and($log->action)->toBe('created')
        ->and($log->actor_id)->toBe($admin->id);
});
