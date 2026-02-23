<?php

namespace App\Http\Requests\PSB;

use Illuminate\Foundation\Http\FormRequest;

class StoreRegistrationPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'year' => ['required', 'string', 'max:20'],
            'wave' => ['required', 'integer', 'min:1'],
            'registration_open' => ['required', 'date'],
            'registration_close' => ['required', 'date', 'after:registration_open'],
            'entry_date' => ['required', 'date', 'after:registration_close'],
            'registration_fee' => ['sometimes', 'numeric', 'min:0'],
            'monthly_tuition_fee' => ['sometimes', 'numeric', 'min:0'],
            'student_quota' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
