<?php

namespace App\Services\Keuangan;

use App\Models\StudentFeeAssignment;
use App\Models\StudentFeeException;
use Carbon\Carbon;

class StudentFeeExceptionService
{
    public function create(StudentFeeAssignment $assignment, array $data, int $userId): StudentFeeException
    {
        $kind = $data['kind'];
        $effectiveFrom = Carbon::parse($data['effective_from'], 'Asia/Jakarta');

        if ($kind === StudentFeeException::KIND_FULL_WAIVER) {
            $this->assertNoActiveFullWaiver($assignment, $effectiveFrom);
        } else {
            $this->assertWithinDiscountCap($assignment, (int) $data['discount_amount'], $effectiveFrom);
        }

        $exception = StudentFeeException::create([
            'student_fee_assignment_id' => $assignment->id,
            'kind' => $kind,
            'discount_amount' => $kind === StudentFeeException::KIND_PARTIAL_NOMINAL ? (int) $data['discount_amount'] : null,
            'reason' => $data['reason'],
            'effective_from' => $data['effective_from'],
            'effective_until' => $data['effective_until'] ?? null,
            'created_by' => $userId,
        ]);

        // Attach the parent so the observer resolves school_id without a lazy load.
        $exception->setRelation('assignment', $assignment);

        return $exception;
    }

    public function update(StudentFeeException $exception, array $data): StudentFeeException
    {
        // kind is immutable — only partial_nominal exceptions reach the cap
        // re-check, and only when discount_amount actually changes. The pivot
        // date is the (possibly new) effective_from of this exception.
        if ($exception->kind === StudentFeeException::KIND_PARTIAL_NOMINAL
            && array_key_exists('discount_amount', $data)) {
            $effectiveFrom = Carbon::parse(
                $data['effective_from'] ?? $exception->effective_from->toDateString(),
                'Asia/Jakarta'
            );
            $this->assertWithinDiscountCap(
                $exception->assignment,
                (int) $data['discount_amount'],
                $effectiveFrom,
                excludeExceptionId: $exception->id,
            );
        }

        $exception->fill(array_intersect_key($data, array_flip([
            'discount_amount', 'reason', 'effective_from', 'effective_until',
        ])));

        if ($exception->isDirty()) {
            $exception->save();
        }

        return $exception->fresh();
    }

    public function delete(StudentFeeException $exception): void
    {
        $exception->delete();
    }

    // FR-020: only one full_waiver whose window overlaps the new exception's
    // start date. An expired full_waiver (window ended before the new start)
    // does NOT block a fresh one.
    private function assertNoActiveFullWaiver(StudentFeeAssignment $assignment, Carbon $atDate): void
    {
        $exists = $assignment->exceptions()
            ->where('kind', StudentFeeException::KIND_FULL_WAIVER)
            ->activeAt($atDate)
            ->exists();

        if ($exists) {
            abort(422, 'Sudah ada beasiswa penuh aktif. Hapus dulu untuk menambah baru.');
        }
    }

    // FR-019: sum of partial_nominal discounts ACTIVE at the new exception's
    // start date + new value must not exceed the locked amount. Expired
    // partials don't count toward the cap (window no longer overlaps).
    private function assertWithinDiscountCap(
        StudentFeeAssignment $assignment,
        int $newDiscount,
        Carbon $atDate,
        ?string $excludeExceptionId = null,
    ): void {
        if ($newDiscount <= 0) {
            abort(422, 'Nominal potongan harus lebih dari 0.');
        }

        $existingSum = (int) $assignment->exceptions()
            ->where('kind', StudentFeeException::KIND_PARTIAL_NOMINAL)
            ->activeAt($atDate)
            ->when($excludeExceptionId !== null, fn ($q) => $q->where('id', '!=', $excludeExceptionId))
            ->sum('discount_amount');

        if ($existingSum + $newDiscount > (int) $assignment->locked_amount) {
            abort(422, 'Total potongan tidak boleh melebihi tarif locked.');
        }
    }
}
