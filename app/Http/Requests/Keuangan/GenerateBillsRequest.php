<?php

namespace App\Http\Requests\Keuangan;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateBillsRequest extends FormRequest
{
    private const CADENCES = ['all', 'monthly', 'quarterly', 'semesterly', 'yearly', 'once_at_enrollment'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period' => ['nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'cadence' => ['nullable', 'string', Rule::in(self::CADENCES)],
        ];
    }

    public function messages(): array
    {
        return [
            'period.regex' => 'Format periode harus YYYY-MM (mis. 2026-05).',
            'cadence.in' => 'Frekuensi tidak valid.',
        ];
    }
}
