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
            'active_exceptions_summary' => [], // populated in US4
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
