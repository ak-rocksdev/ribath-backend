<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

class StudentCompletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isComplete = $this->input('status') === 'completed';
        $requiredIf = $isComplete ? 'required' : 'nullable';

        return [
            'status' => ['required', 'in:draft,completed'],

            // Student fields
            'student' => ['nullable', 'array'],
            'student.nik' => [$requiredIf, 'nullable', 'string', 'size:16'],
            'student.email' => ['nullable', 'email', 'max:255'],
            'student.phone' => ['nullable', 'string', 'max:20'],
            'student.address' => [$requiredIf, 'nullable', 'string', 'max:500'],
            'student.child_order' => ['nullable', 'integer', 'min:1'],
            'student.siblings_count' => ['nullable', 'integer', 'min:0'],
            'student.family_income_range' => ['nullable', 'string', 'max:50'],
            'student.motivation' => ['nullable', 'string', 'max:2000'],

            // Parents
            'parents' => ['nullable', 'array'],
            'parents.father' => ['nullable', 'array'],
            'parents.father.name' => [$requiredIf, 'nullable', 'string', 'max:100'],
            'parents.father.occupation' => ['nullable', 'string', 'max:100'],
            'parents.father.email' => ['nullable', 'email', 'max:255'],
            'parents.father.phone' => [$requiredIf, 'nullable', 'string', 'max:20'],
            'parents.father.address' => ['nullable', 'string', 'max:500'],
            'parents.mother' => ['nullable', 'array'],
            'parents.mother.name' => [$requiredIf, 'nullable', 'string', 'max:100'],
            'parents.mother.occupation' => ['nullable', 'string', 'max:100'],
            'parents.mother.email' => ['nullable', 'email', 'max:255'],
            'parents.mother.phone' => [$requiredIf, 'nullable', 'string', 'max:20'],

            // Health
            'health' => ['nullable', 'array'],
            'health.blood_type' => [$requiredIf, 'nullable', 'string', 'in:A,B,AB,O,unknown'],
            'health.disease_history' => ['nullable', 'string', 'max:2000'],
            'health.allergies' => ['nullable', 'string', 'max:2000'],
            'health.special_conditions' => ['nullable', 'string', 'max:2000'],

            // Education history
            'education_history' => ['nullable', 'array'],
            'education_history.last_school_name' => [$requiredIf, 'nullable', 'string', 'max:200'],
            'education_history.last_education_level' => [$requiredIf, 'nullable', 'string', 'in:elementary,middle_school,high_school'],
            'education_history.graduation_year' => ['nullable', 'integer', 'min:2000', 'max:2030'],
            'education_history.achievements' => ['nullable', 'string', 'max:2000'],

            // Religious profile
            'religious_profile' => ['nullable', 'array'],
            'religious_profile.quran_reading_ability' => [$requiredIf, 'nullable', 'string', 'in:fluent,basic,unable'],
            'religious_profile.memorized_juz' => ['nullable', 'integer', 'min:0', 'max:30'],
            'religious_profile.has_pesantren_experience' => ['nullable', 'boolean'],
            'religious_profile.previous_pesantren_name' => ['nullable', 'string', 'max:200'],
            'religious_profile.other_skills' => ['nullable', 'string', 'max:2000'],

            // Additional info
            'additional_info' => ['nullable', 'array'],
            'additional_info.hobbies_talents' => ['nullable', 'string', 'max:500'],
            'additional_info.extracurricular_interests' => ['nullable', 'string', 'max:500'],
            'additional_info.post_graduation_hopes' => ['nullable', 'string', 'max:2000'],

            // Agreements (required only on completion)
            'agreements' => [$isComplete ? 'required' : 'nullable', 'array'],
            'agreements.agreed_to_rules' => $isComplete
                ? ['required', 'accepted']
                : ['nullable', 'boolean'],
            'agreements.agreed_to_commitment' => $isComplete
                ? ['required', 'accepted']
                : ['nullable', 'boolean'],
            'agreements.data_verified' => $isComplete
                ? ['required', 'accepted']
                : ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'student.nik.size' => 'NIK harus 16 digit',
            'agreements.agreed_to_rules.accepted' => 'Anda harus menyetujui tata tertib',
            'agreements.agreed_to_commitment.accepted' => 'Anda harus menyetujui komitmen',
            'agreements.data_verified.accepted' => 'Anda harus memverifikasi kebenaran data',
        ];
    }
}
