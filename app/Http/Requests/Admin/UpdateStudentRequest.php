<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Core student fields
            'full_name' => ['sometimes', 'string', 'max:100'],
            'nik' => ['nullable', 'string', 'size:16'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'birth_place' => ['nullable', 'string', 'max:100'],
            'birth_date' => ['sometimes', 'date', 'before:today'],
            'gender' => ['sometimes', Rule::in(['L', 'P'])],
            'child_order' => ['nullable', 'integer', 'min:1'],
            'siblings_count' => ['nullable', 'integer', 'min:0'],
            'family_income_range' => ['nullable', 'string', 'max:50'],
            'motivation' => ['nullable', 'string', 'max:2000'],
            'program' => ['sometimes', Rule::in(['tahfidz', 'regular'])],
            'entry_date' => ['sometimes', 'date'],
            'class_level' => ['nullable', 'string', 'exists:class_levels,slug'],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string'],
            'guardian_user_id' => ['nullable', 'exists:users,id'],

            // Parents (nested)
            'parents' => ['nullable', 'array'],
            'parents.father' => ['nullable', 'array'],
            'parents.father.name' => ['nullable', 'string', 'max:100'],
            'parents.father.occupation' => ['nullable', 'string', 'max:100'],
            'parents.father.email' => ['nullable', 'email', 'max:255'],
            'parents.father.phone' => ['nullable', 'string', 'max:20'],
            'parents.father.address' => ['nullable', 'string', 'max:500'],
            'parents.mother' => ['nullable', 'array'],
            'parents.mother.name' => ['nullable', 'string', 'max:100'],
            'parents.mother.occupation' => ['nullable', 'string', 'max:100'],
            'parents.mother.email' => ['nullable', 'email', 'max:255'],
            'parents.mother.phone' => ['nullable', 'string', 'max:20'],
            'parents.mother.address' => ['nullable', 'string', 'max:500'],

            // Health
            'health' => ['nullable', 'array'],
            'health.blood_type' => ['nullable', 'string', Rule::in(['A', 'B', 'AB', 'O', 'unknown'])],
            'health.disease_history' => ['nullable', 'string', 'max:2000'],
            'health.allergies' => ['nullable', 'string', 'max:2000'],
            'health.special_conditions' => ['nullable', 'string', 'max:2000'],

            // Education history
            'education_history' => ['nullable', 'array'],
            'education_history.last_school_name' => ['nullable', 'string', 'max:200'],
            'education_history.last_education_level' => ['nullable', 'string', Rule::in(['elementary', 'middle_school', 'high_school'])],
            'education_history.graduation_year' => ['nullable', 'integer', 'min:2000', 'max:2030'],
            'education_history.achievements' => ['nullable', 'string', 'max:2000'],

            // Religious profile
            'religious_profile' => ['nullable', 'array'],
            'religious_profile.quran_reading_ability' => ['nullable', 'string', Rule::in(['fluent', 'basic', 'unable'])],
            'religious_profile.memorized_juz' => ['nullable', 'integer', 'min:0', 'max:30'],
            'religious_profile.has_pesantren_experience' => ['nullable', 'boolean'],
            'religious_profile.previous_pesantren_name' => ['nullable', 'string', 'max:200'],
            'religious_profile.other_skills' => ['nullable', 'string', 'max:2000'],

            // Additional info
            'additional_info' => ['nullable', 'array'],
            'additional_info.hobbies_talents' => ['nullable', 'string', 'max:500'],
            'additional_info.extracurricular_interests' => ['nullable', 'string', 'max:500'],
            'additional_info.post_graduation_hopes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
