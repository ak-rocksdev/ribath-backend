<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ExportTeacherSchedulePdfRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_year_id' => ['required', 'uuid', 'exists:academic_years,id'],
            'semester'         => ['required', 'integer', 'in:1,2'],
            'orientation'      => ['nullable', 'in:portrait,landscape'],
        ];
    }

    public function orientation(): string
    {
        return $this->validated('orientation') ?? 'landscape';
    }
}
