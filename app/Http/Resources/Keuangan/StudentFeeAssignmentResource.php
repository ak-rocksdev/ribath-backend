<?php

namespace App\Http\Resources\Keuangan;

use Illuminate\Http\Resources\Json\JsonResource;

class StudentFeeAssignmentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'fee_type_id' => $this->fee_type_id,
            'fee_type' => $this->whenLoaded('feeType', fn () => [
                'id' => $this->feeType->id,
                'code' => $this->feeType->code,
                'label' => $this->feeType->label,
                'is_active' => $this->feeType->is_active,
            ]),
            'locked_amount' => (int) $this->locked_amount,
            'cadence' => $this->cadence,
            'source_academic_year_id' => $this->source_academic_year_id,
            'source_academic_year' => $this->whenLoaded('sourceAcademicYear', fn () => [
                'id' => $this->sourceAcademicYear->id,
                'name' => $this->sourceAcademicYear->name,
            ]),
            'source' => $this->source,
            // exception support added in US4 — for now effective == locked.
            'effective_amount_current_period' => (int) $this->locked_amount,
            'active_exceptions' => [],
            'created_by' => $this->created_by,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
