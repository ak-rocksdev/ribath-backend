<?php

use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\CashBookCategory;
use App\Models\FeeSchedule;
use App\Models\FeeType;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentFeeAssignment;
use App\Models\StudentPayment;
use App\Models\User;
use App\Services\Keuangan\BillGeneratorService;
use App\Services\Keuangan\StudentPaymentService;
use Spatie\Permission\Models\Permission;

// Performance guard tests for the spec Success Criteria (SC-005 bulk payment,
// bill generator throughput). Bounds are deliberately generous — these assert
// "no pathological N+1 / quadratic blowup", not a precise SLA. Real prod
// numbers will differ (Postgres vs SQLite, network); a failure here means the
// code regressed into per-row queries or similar, not that prod is too slow.

beforeEach(function () {
    foreach (['view-student-fees', 'manage-student-fees', 'record-payments'] as $perm) {
        Permission::firstOrCreate(['name' => $perm]);
    }

    $this->school = School::factory()->create(['is_active' => true]);
    $this->ay = AcademicYear::factory()->create(['school_id' => $this->school->id, 'is_active' => true]);
    $this->category = CashBookCategory::factory()->for($this->school, 'school')->create();
    $this->spp = FeeType::factory()->forSchool($this->school)->withCadence('monthly')
        ->create(['code' => 'spp', 'cash_book_category_id' => $this->category->id]);
    FeeSchedule::factory()->forSchool($this->school)->forAcademicYear($this->ay)->forFeeType($this->spp)
        ->create(['amount' => 500000]);
    $this->user = User::factory()->create();
});

test('SC-005: bulk record 50 payments completes well under 10s', function () {
    $items = [];
    for ($i = 0; $i < 50; $i++) {
        $student = Student::factory()->create(['school_id' => $this->school->id]);
        $assignment = StudentFeeAssignment::factory()->forStudent($student)->forFeeType($this->spp)
            ->forAcademicYear($this->ay)->create(['locked_amount' => 500000, 'cadence' => 'monthly']);
        $bill = Bill::factory()->forAssignment($assignment)->pending()->create();
        $items[] = [
            'bill_id' => $bill->id,
            'amount' => 500000,
            'payment_date' => now('Asia/Jakarta')->toDateString(),
            'payment_method' => 'transfer',
        ];
    }

    $service = app(StudentPaymentService::class);

    $start = microtime(true);
    $result = $service->bulkRecord($items, $this->user->id);
    $elapsed = microtime(true) - $start;

    expect($result['payments'])->toHaveCount(50)
        ->and(StudentPayment::count())->toBe(50)
        ->and($elapsed)->toBeLessThan(10.0);
});

test('bill generator processes 100 active assignments without per-row query blowup', function () {
    for ($i = 0; $i < 100; $i++) {
        $student = Student::factory()->create(['school_id' => $this->school->id, 'status' => 'active']);
        StudentFeeAssignment::factory()->forStudent($student)->forFeeType($this->spp)
            ->forAcademicYear($this->ay)->create(['locked_amount' => 500000, 'cadence' => 'monthly']);
    }

    $generator = app(BillGeneratorService::class);

    $start = microtime(true);
    $stats = $generator->generate(now('Asia/Jakarta')->format('Y-m'));
    $elapsed = microtime(true) - $start;

    expect($stats['bills_created'])->toBe(100)
        ->and($elapsed)->toBeLessThan(15.0);
});

test('bill generator is idempotent at scale — re-run creates zero', function () {
    for ($i = 0; $i < 30; $i++) {
        $student = Student::factory()->create(['school_id' => $this->school->id, 'status' => 'active']);
        StudentFeeAssignment::factory()->forStudent($student)->forFeeType($this->spp)
            ->forAcademicYear($this->ay)->create(['cadence' => 'monthly']);
    }

    $generator = app(BillGeneratorService::class);
    $generator->generate(now('Asia/Jakarta')->format('Y-m'));
    $second = $generator->generate(now('Asia/Jakarta')->format('Y-m'));

    expect($second['bills_created'])->toBe(0)
        ->and($second['bills_skipped_existing'])->toBe(30)
        ->and(Bill::count())->toBe(30);
});
