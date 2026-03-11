<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreAcademicYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:20', 'regex:/^\d{4}\/\d{4}$/'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'active_semester' => ['sometimes', 'integer', 'in:1,2'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex' => 'Name must be in format YYYY/YYYY (e.g., 2025/2026).',
            'end_date.after' => 'End date must be after start date.',
        ];
    }
}
