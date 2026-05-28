<?php

namespace App\Http\Resources\Keuangan;

use App\Models\StudentFeeException;
use App\Services\Keuangan\EffectiveAmountCalculator;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentFeeAssignmentResource extends JsonResource
{
    public function toArray($request): array
    {
        $today = now('Asia/Jakarta');

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
            'effective_amount_current_period' => app(EffectiveAmountCalculator::class)
                ->compute($this->resource, $today),
            'active_exceptions' => $this->relationLoaded('exceptions')
                ? $this->activeExceptions($today->toDateString())
                : [],
            'created_by' => $this->created_by,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    private function activeExceptions(string $atDate): array
    {
        return $this->exceptions
            ->filter(fn (StudentFeeException $e) => StudentFeeException::isActiveAt($e, $atDate))
            ->map(fn (StudentFeeException $e) => [
                'id' => $e->id,
                'kind' => $e->kind,
                'discount_amount' => $e->discount_amount !== null ? (int) $e->discount_amount : null,
                'reason' => $e->reason,
                'effective_from' => $e->effective_from->toDateString(),
                'effective_until' => $e->effective_until?->toDateString(),
            ])
            ->values()
            ->all();
    }
}
