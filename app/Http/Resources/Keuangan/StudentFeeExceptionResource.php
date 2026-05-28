<?php

namespace App\Http\Resources\Keuangan;

use Illuminate\Http\Resources\Json\JsonResource;

class StudentFeeExceptionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'student_fee_assignment_id' => $this->student_fee_assignment_id,
            'kind' => $this->kind,
            'discount_amount' => $this->discount_amount !== null ? (int) $this->discount_amount : null,
            'reason' => $this->reason,
            'effective_from' => $this->effective_from->toDateString(),
            'effective_until' => $this->effective_until?->toDateString(),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
