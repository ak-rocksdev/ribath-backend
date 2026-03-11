<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSubjectBookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:100'],
            'subject_category_id' => ['sometimes', 'exists:subject_categories,id'],
            'class_levels' => ['sometimes', 'array', 'min:1'],
            'class_levels.*' => ['string', 'exists:class_levels,slug'],
            'semesters' => ['sometimes', 'array', 'min:1'],
            'semesters.*' => ['integer', 'in:1,2'],
            'sessions_per_week' => ['sometimes', 'integer', 'min:1', 'max:7'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
