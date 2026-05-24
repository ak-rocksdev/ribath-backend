<?php

namespace App\Http\Requests\Keuangan;

use App\Models\FeeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFeeScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // (academic_year_id, fee_type_id) intentionally absent — immutable
        // pair so the unique constraint stays stable. Admins create a new
        // schedule for a different combo instead of editing the FK pair.
        return [
            'amount' => ['sometimes', 'integer', 'min:0', 'max:999999999999'],
            'cadence_override' => ['sometimes', 'nullable', 'string', Rule::in(FeeType::CADENCES)],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.integer' => 'Nominal tarif harus berupa angka bulat.',
            'amount.min' => 'Nominal tarif tidak boleh negatif.',
            'cadence_override.in' => 'Cadence tidak valid.',
        ];
    }
}
