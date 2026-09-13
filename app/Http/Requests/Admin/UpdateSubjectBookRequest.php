<?php

namespace App\Http\Requests\Admin;

use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubjectBookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $school = School::activeOrFail();

        return [
            'title' => ['sometimes', 'string', 'max:100'],
            'subject_category_id' => ['sometimes', 'exists:subject_categories,id'],
            'grading_template_id' => [
                'nullable',
                'uuid',
                Rule::exists('grading_templates', 'id')->where('school_id', $school->id),
            ],
            'class_levels' => ['sometimes', 'array', 'min:1'],
            'class_levels.*' => ['string', 'exists:class_levels,slug'],
            'semesters' => ['sometimes', 'array', 'min:1'],
            'semesters.*' => ['integer', 'in:1,2'],
            'sessions_per_week' => ['sometimes', 'integer', 'min:1', 'max:7'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'grading_template_id.exists' => 'Template penilaian tidak ditemukan untuk pesantren ini.',
        ];
    }
}
