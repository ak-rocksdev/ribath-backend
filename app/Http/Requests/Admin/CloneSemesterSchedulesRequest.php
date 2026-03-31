<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CloneSemesterSchedulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_academic_year_id' => ['required', 'uuid', 'exists:academic_years,id'],
            'source_semester' => ['required', 'integer', 'in:1,2'],
            'target_academic_year_id' => ['required', 'uuid', 'exists:academic_years,id'],
            'target_semester' => ['required', 'integer', 'in:1,2'],
            'exclude_schedule_ids' => ['sometimes', 'array'],
            'exclude_schedule_ids.*' => ['uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'source_academic_year_id.required' => 'Source academic year is required.',
            'target_academic_year_id.required' => 'Target academic year is required.',
        ];
    }
}
