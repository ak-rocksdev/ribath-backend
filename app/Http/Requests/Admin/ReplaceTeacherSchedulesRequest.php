<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ReplaceTeacherSchedulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_teacher_id' => ['required', 'uuid', 'exists:teachers,id'],
            'target_teacher_id' => ['required', 'uuid', 'exists:teachers,id', 'different:source_teacher_id'],
            'academic_year_id' => ['sometimes', 'uuid', 'exists:academic_years,id'],
            'semester' => ['sometimes', 'integer', 'in:1,2'],
        ];
    }

    public function messages(): array
    {
        return [
            'target_teacher_id.different' => 'Target teacher must be different from source teacher.',
        ];
    }
}
