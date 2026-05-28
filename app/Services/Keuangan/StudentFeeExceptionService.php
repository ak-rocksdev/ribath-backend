<?php

namespace App\Services\Keuangan;

use App\Models\StudentFeeAssignment;
use App\Models\StudentFeeException;

class StudentFeeExceptionService
{
    public function create(StudentFeeAssignment $assignment, array $data, int $userId): StudentFeeException
    {
        $kind = $data['kind'];

        if ($kind === StudentFeeException::KIND_FULL_WAIVER) {
            $this->assertNoActiveFullWaiver($assignment);
        } else {
            $this->assertWithinDiscountCap($assignment, (int) $data['discount_amount']);
        }

        return StudentFeeException::create([
            'student_fee_assignment_id' => $assignment->id,
            'kind' => $kind,
            'discount_amount' => $kind === StudentFeeException::KIND_PARTIAL_NOMINAL ? (int) $data['discount_amount'] : null,
            'reason' => $data['reason'],
            'effective_from' => $data['effective_from'],
            'effective_until' => $data['effective_until'] ?? null,
            'created_by' => $userId,
        ]);
    }

    public function update(StudentFeeException $exception, array $data): StudentFeeException
    {
        // kind is immutable — only partial_nominal exceptions reach the cap
        // re-check, and only when discount_amount actually changes.
        if ($exception->kind === StudentFeeException::KIND_PARTIAL_NOMINAL
            && array_key_exists('discount_amount', $data)) {
            $this->assertWithinDiscountCap(
                $exception->assignment,
                (int) $data['discount_amount'],
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

    // FR-020: only one active full_waiver per assignment.
    private function assertNoActiveFullWaiver(StudentFeeAssignment $assignment): void
    {
        $exists = $assignment->exceptions()
            ->where('kind', StudentFeeException::KIND_FULL_WAIVER)
            ->exists();

        if ($exists) {
            abort(422, 'Sudah ada beasiswa penuh aktif. Hapus dulu untuk menambah baru.');
        }
    }

    // FR-019: sum of active partial_nominal discounts + new value must not
    // exceed the locked amount (effective amount caps at 0, no negative bill).
    private function assertWithinDiscountCap(
        StudentFeeAssignment $assignment,
        int $newDiscount,
        ?string $excludeExceptionId = null,
    ): void {
        if ($newDiscount <= 0) {
            abort(422, 'Nominal potongan harus lebih dari 0.');
        }

        $existingSum = (int) $assignment->exceptions()
            ->where('kind', StudentFeeException::KIND_PARTIAL_NOMINAL)
            ->when($excludeExceptionId !== null, fn ($q) => $q->where('id', '!=', $excludeExceptionId))
            ->sum('discount_amount');

        if ($existingSum + $newDiscount > (int) $assignment->locked_amount) {
            abort(422, 'Total potongan tidak boleh melebihi tarif locked.');
        }
    }
}
