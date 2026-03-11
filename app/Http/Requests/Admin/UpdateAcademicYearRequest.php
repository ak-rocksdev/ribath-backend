<?php

namespace App\Http\Requests\Admin;

use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAcademicYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'sometimes', 'string', 'max:20', 'regex:/^\d{4}\/\d{4}$/',
                Rule::unique('academic_years')
                    ->where('school_id', School::activeOrFail()->id)
                    ->ignore($this->route('academicYear')?->id),
            ],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date', function ($attribute, $value, $fail) {
                $startDate = $this->input('start_date', $this->route('academicYear')?->start_date?->toDateString());
                if ($startDate && $value <= $startDate) {
                    $fail('The end date must be after the start date.');
                }
            }],
            'active_semester' => ['sometimes', 'integer', 'in:1,2'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex' => 'Name must be in format YYYY/YYYY (e.g., 2025/2026).',
        ];
    }
}
