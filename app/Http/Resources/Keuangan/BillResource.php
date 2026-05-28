<?php

namespace App\Http\Resources\Keuangan;

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
            'active_exceptions_summary' => $this->activeExceptionsSummary(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    // Exceptions active at this bill's billing_period_start, serialized for the
    // tagihan UI. Returns [] when the assignment.exceptions relation wasn't
    // eager-loaded (e.g. list endpoints that don't need it) to avoid N+1.
    private function activeExceptionsSummary(): array
    {
        $assignment = $this->whenLoaded('assignment');
        if (! $assignment instanceof \App\Models\StudentFeeAssignment || ! $assignment->relationLoaded('exceptions')) {
            return [];
        }

        $periodStart = $this->billing_period_start->toDateString();

        return $assignment->exceptions
            ->filter(function (\App\Models\StudentFeeException $e) use ($periodStart) {
                $from = $e->effective_from?->toDateString();
                $until = $e->effective_until?->toDateString();

                return $from !== null
                    && $from <= $periodStart
                    && ($until === null || $until >= $periodStart);
            })
            ->map(fn (\App\Models\StudentFeeException $e) => [
                'kind' => $e->kind,
                'discount_amount' => $e->discount_amount !== null ? (int) $e->discount_amount : null,
                'reason' => $e->reason,
            ])
            ->values()
            ->all();
    }
}
