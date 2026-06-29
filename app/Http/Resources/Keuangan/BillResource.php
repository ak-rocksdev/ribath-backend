<?php

namespace App\Http\Resources\Keuangan;

use App\Models\StudentFeeAssignment;
use App\Models\StudentFeeException;
use Illuminate\Http\Resources\Json\JsonResource;

class BillResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'student' => $this->whenLoaded('student', fn () => [
                'id' => $this->student->id,
                'full_name' => $this->student->full_name,
                'class_level' => $this->student->class_level,
            ]),
            'student_fee_assignment_id' => $this->student_fee_assignment_id,
            'assignment' => $this->whenLoaded('assignment', fn () => [
                'id' => $this->assignment->id,
                'fee_type_id' => $this->assignment->fee_type_id,
                'fee_type' => $this->assignment->relationLoaded('feeType') && $this->assignment->feeType ? [
                    'id' => $this->assignment->feeType->id,
                    'code' => $this->assignment->feeType->code,
                    'label' => $this->assignment->feeType->label,
                ] : null,
            ]),
            'billing_period_start' => $this->billing_period_start->toDateString(),
            'billing_period_end' => $this->billing_period_end->toDateString(),
            'cadence_at_generation' => $this->cadence_at_generation,
            'expected_amount' => (int) $this->expected_amount,
            'paid_amount' => (int) $this->paid_amount,
            'remaining_amount' => max(0, (int) $this->expected_amount - (int) $this->paid_amount),
            'status' => $this->status,
            'due_date' => $this->due_date->toDateString(),
            // Only emit when the relation chain is loaded (show endpoint) — the
            // list endpoint deliberately skips the eager-load to avoid N+1, so
            // the key is ABSENT there rather than a misleading empty array a
            // consumer can't distinguish from "genuinely no exceptions".
            $this->mergeWhen($this->exceptionsRelationLoaded(), fn () => [
                'active_exceptions_summary' => $this->activeExceptionsSummary(),
            ]),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    private function exceptionsRelationLoaded(): bool
    {
        $assignment = $this->whenLoaded('assignment');

        return $assignment instanceof StudentFeeAssignment && $assignment->relationLoaded('exceptions');
    }

    // Exceptions active at this bill's billing_period_start, serialized for the
    // tagihan UI. Precondition: assignment.exceptions eager-loaded (gated above).
    private function activeExceptionsSummary(): array
    {
        $periodStart = $this->billing_period_start->toDateString();

        return $this->assignment->exceptions
            ->filter(fn (StudentFeeException $e) => StudentFeeException::isActiveAt($e, $periodStart))
            ->map(fn (StudentFeeException $e) => [
                'kind' => $e->kind,
                'discount_amount' => $e->discount_amount !== null ? (int) $e->discount_amount : null,
                'reason' => $e->reason,
            ])
            ->values()
            ->all();
    }
}
