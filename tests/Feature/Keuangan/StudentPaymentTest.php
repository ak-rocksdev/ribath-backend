<?php

use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\CashBookActivityLog;
use App\Models\CashBookCategory;
use App\Models\CashBookEntry;
use App\Models\ClassLevel;
use App\Models\FeeActivityLog;
use App\Models\FeeSchedule;
use App\Models\FeeType;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentFeeAssignment;
use App\Models\StudentPayment;
use App\Models\User;
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

    $this->student = Student::factory()->create(['school_id' => $this->school->id, 'full_name' => 'Hasbullah']);
    $this->assignment = StudentFeeAssignment::factory()
        ->forStudent($this->student)
        ->forFeeType($this->spp)
        ->forAcademicYear($this->ay)
        ->create(['locked_amount' => 500000, 'cadence' => 'monthly']);
    $this->bill = Bill::factory()
        ->forAssignment($this->assignment)
        ->pending()
        ->create();
});

function makePaymentManagerUser(): User
{
    $user = User::factory()->create();
    $role = Role::firstOrCreate(['name' => 'pengurus_pesantren']);
    $role->syncPermissions(['view-student-fees', 'manage-student-fees', 'record-payments']);
    $user->assignRole($role);

    return $user;
}

function makeNoPaymentUser(): User
{
    $user = User::factory()->create();
    $role = Role::firstOrCreate(['name' => 'no_pay_role']);
    $role->syncPermissions([]);
    $user->assignRole($role);

    return $user;
}

// ──────────────────────────────────────────────────────
// Single payment + atomic cash_book_entry creation
// ──────────────────────────────────────────────────────

test('single payment creates linked cash_book_entry atomically (FR-029, FR-030)', function () {
    $admin = makePaymentManagerUser();

    $this->actingAs($admin)
        ->postJson('/api/v1/student-payments', [
            'bill_id' => $this->bill->id,
            'amount' => 500000,
            'payment_date' => now('Asia/Jakarta')->toDateString(),
            'payment_method' => 'transfer',
        ])
        ->assertCreated();

    $payment = StudentPayment::first();
    expect($payment)->not->toBeNull()
        ->and($payment->cash_book_entry_id)->not->toBeNull();

    $entry = CashBookEntry::find($payment->cash_book_entry_id);
    expect($entry)->not->toBeNull()
        ->and((int) $entry->amount)->toBe(500000)
        ->and($entry->type)->toBe('in')
        ->and($entry->category_id)->toBe($this->category->id)
        ->and($entry->counterparty)->toBe('Hasbullah');

    $this->bill->refresh();
    expect((int) $this->bill->paid_amount)->toBe(500000)
        ->and($this->bill->status)->toBe(Bill::STATUS_PAID);
});

test('partial payment leaves status partial', function () {
    $admin = makePaymentManagerUser();

    $this->actingAs($admin)
        ->postJson('/api/v1/student-payments', [
            'bill_id' => $this->bill->id,
            'amount' => 300000,
            'payment_date' => now('Asia/Jakarta')->toDateString(),
            'payment_method' => 'cash',
        ])
        ->assertCreated();

    $this->bill->refresh();
    expect((int) $this->bill->paid_amount)->toBe(300000)
        ->and($this->bill->status)->toBe(Bill::STATUS_PARTIAL);
});

test('payment rejected when amount exceeds remaining', function () {
    $admin = makePaymentManagerUser();

    $this->actingAs($admin)
        ->postJson('/api/v1/student-payments', [
            'bill_id' => $this->bill->id,
            'amount' => 600000,
            'payment_date' => now('Asia/Jakarta')->toDateString(),
            'payment_method' => 'transfer',
        ])
        ->assertUnprocessable();

    expect(StudentPayment::count())->toBe(0)
        ->and(CashBookEntry::count())->toBe(0);
});

test('waived bill rejects payment', function () {
    $this->bill->update(['status' => Bill::STATUS_WAIVED, 'expected_amount' => 0]);
    $admin = makePaymentManagerUser();

    $this->actingAs($admin)
        ->postJson('/api/v1/student-payments', [
            'bill_id' => $this->bill->id,
            'amount' => 1,
            'payment_date' => now('Asia/Jakarta')->toDateString(),
            'payment_method' => 'cash',
        ])
        ->assertUnprocessable();
});

// ──────────────────────────────────────────────────────
// Bulk
// ──────────────────────────────────────────────────────

test('bulk all-or-nothing rollback on one invalid item (FR-028, Clarifications Q2)', function () {
    $student2 = Student::factory()->create(['school_id' => $this->school->id]);
    $assignment2 = StudentFeeAssignment::factory()->forStudent($student2)->forFeeType($this->spp)
        ->forAcademicYear($this->ay)->create(['locked_amount' => 500000, 'cadence' => 'monthly']);
    $bill2 = Bill::factory()->forAssignment($assignment2)->pending()->create();

    $admin = makePaymentManagerUser();

    $this->actingAs($admin)
        ->postJson('/api/v1/student-payments/bulk', [
            'items' => [
                ['bill_id' => $this->bill->id, 'amount' => 500000, 'payment_date' => now('Asia/Jakarta')->toDateString(), 'payment_method' => 'transfer'],
                ['bill_id' => $bill2->id, 'amount' => 99999999, 'payment_date' => now('Asia/Jakarta')->toDateString(), 'payment_method' => 'transfer'], // exceeds remaining
            ],
        ])
        ->assertUnprocessable();

    expect(StudentPayment::count())->toBe(0)
        ->and(CashBookEntry::count())->toBe(0);
});

test('bulk happy path 5 items creates all + 5 cash_book_entries', function () {
    $admin = makePaymentManagerUser();
    $bills = [];
    for ($i = 0; $i < 4; $i++) {
        $st = Student::factory()->create(['school_id' => $this->school->id]);
        $as = StudentFeeAssignment::factory()->forStudent($st)->forFeeType($this->spp)
            ->forAcademicYear($this->ay)->create(['locked_amount' => 500000, 'cadence' => 'monthly']);
        $bills[] = Bill::factory()->forAssignment($as)->pending()->create();
    }

    $items = collect([$this->bill, ...$bills])->map(fn ($b) => [
        'bill_id' => $b->id,
        'amount' => 500000,
        'payment_date' => now('Asia/Jakarta')->toDateString(),
        'payment_method' => 'transfer',
    ])->all();

    $this->actingAs($admin)
        ->postJson('/api/v1/student-payments/bulk', ['items' => $items])
        ->assertCreated();

    expect(StudentPayment::count())->toBe(5)
        ->and(CashBookEntry::count())->toBe(5);
});

// ──────────────────────────────────────────────────────
// Reversal (FR-031 + Clarifications Q2 withoutEvents semantic)
// ──────────────────────────────────────────────────────

test('reversal soft-deletes payment + cash_book_entry + decrements bill + 1 reversed log per side', function () {
    $admin = makePaymentManagerUser();

    // Record payment first
    $this->actingAs($admin)
        ->postJson('/api/v1/student-payments', [
            'bill_id' => $this->bill->id,
            'amount' => 500000,
            'payment_date' => now('Asia/Jakarta')->toDateString(),
            'payment_method' => 'transfer',
        ])
        ->assertCreated();

    $payment = StudentPayment::first();
    $entryId = $payment->cash_book_entry_id;

    // Now reverse
    $this->actingAs($admin)
        ->deleteJson("/api/v1/student-payments/{$payment->id}", ['reason' => 'Salah input'])
        ->assertOk();

    // Both soft-deleted
    expect(StudentPayment::count())->toBe(0)
        ->and(StudentPayment::withTrashed()->whereNotNull('deleted_at')->count())->toBe(1)
        ->and(CashBookEntry::count())->toBe(0)
        ->and(CashBookEntry::withTrashed()->whereNotNull('deleted_at')->where('id', $entryId)->count())->toBe(1);

    // Bill rolled back
    $this->bill->refresh();
    expect((int) $this->bill->paid_amount)->toBe(0)
        ->and($this->bill->status)->toBe(Bill::STATUS_PENDING);

    // Exactly ONE reversed log on Fee side (no `deleted` row from suppressed observer)
    $feeReversed = FeeActivityLog::where('action', FeeActivityLog::ACTION_REVERSED)
        ->where('subject_type', FeeActivityLog::SUBJECT_PAYMENT)->count();
    $feeDeleted = FeeActivityLog::where('action', FeeActivityLog::ACTION_DELETED)
        ->where('subject_type', FeeActivityLog::SUBJECT_PAYMENT)->count();
    expect($feeReversed)->toBe(1)
        ->and($feeDeleted)->toBe(0);

    // Exactly ONE reversed log on CashBook side
    $cbReversed = CashBookActivityLog::where('action', CashBookActivityLog::ACTION_REVERSED)->count();
    expect($cbReversed)->toBe(1);
});

// ──────────────────────────────────────────────────────
// Multi-tenant + permission
// ──────────────────────────────────────────────────────

test('cross-tenant payment returns 404', function () {
    $otherSchool = School::factory()->create(['is_active' => false]);
    $otherStudent = Student::factory()->create(['school_id' => $otherSchool->id]);
    $otherAssignment = StudentFeeAssignment::factory()->forStudent($otherStudent)->forFeeType($this->spp)
        ->forAcademicYear($this->ay)->create();
    $otherBill = Bill::factory()->forAssignment($otherAssignment)->create();
    $payment = StudentPayment::factory()->forBill($otherBill)->create();
    $admin = makePaymentManagerUser();

    $this->actingAs($admin)
        ->deleteJson("/api/v1/student-payments/{$payment->id}")
        ->assertNotFound();
});

test('user without record-payments cannot record', function () {
    $user = makeNoPaymentUser();

    $this->actingAs($user)
        ->postJson('/api/v1/student-payments', [
            'bill_id' => $this->bill->id,
            'amount' => 500000,
            'payment_date' => now('Asia/Jakarta')->toDateString(),
            'payment_method' => 'transfer',
        ])
        ->assertForbidden();
});

test('payments index endpoint returns list with eager loaded refs', function () {
    StudentPayment::factory()->forBill($this->bill)->create(['amount' => 500000]);
    $admin = makePaymentManagerUser();

    $this->actingAs($admin)
        ->getJson('/api/v1/student-payments')
        ->assertOk()
        ->assertJsonPath('data.0.amount', 500000)
        ->assertJsonPath('data.0.student.full_name', 'Hasbullah');
});

// ──────────────────────────────────────────────────────
// Calculator stub
// ──────────────────────────────────────────────────────

test('EffectiveAmountCalculator returns locked_amount in US3 stub', function () {
    $calc = app(\App\Services\Keuangan\EffectiveAmountCalculator::class);
    expect($calc->compute($this->assignment, now()))->toBe(500000);
});
