<?php

namespace App\Http\Resources\Keuangan;

use Illuminate\Http\Resources\Json\JsonResource;

class StudentPaymentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'bill_id' => $this->bill_id,
            'bill' => $this->whenLoaded('bill', fn () => [
                'id' => $this->bill->id,
                'billing_period_start' => $this->bill->billing_period_start->toDateString(),
                'expected_amount' => (int) $this->bill->expected_amount,
                'status' => $this->bill->status,
            ]),
            'student_id' => $this->student_id,
            'student' => $this->whenLoaded('student', fn () => [
                'id' => $this->student->id,
                'full_name' => $this->student->full_name,
                'class_level' => $this->student->class_level,
            ]),
            'amount' => (int) $this->amount,
            'payment_date' => $this->payment_date->toDateString(),
            'payment_method' => $this->payment_method,
            'cash_book_entry_id' => $this->cash_book_entry_id,
            'cash_book_entry' => $this->whenLoaded('cashBookEntry', fn () => $this->cashBookEntry ? [
                'id' => $this->cashBookEntry->id,
                'description' => $this->cashBookEntry->description,
                'amount' => (int) $this->cashBookEntry->amount,
            ] : null),
            'has_proof' => $this->proof_file_path !== null,
            'notes' => $this->notes,
            'confirmed_by' => $this->confirmed_by,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
