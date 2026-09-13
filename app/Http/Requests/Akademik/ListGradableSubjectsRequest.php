<?php

namespace App\Http\Requests\Akademik;

use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListGradableSubjectsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $school = School::activeOrFail();

        return [
            'academic_year_id' => [
                'required',
                'uuid',
                Rule::exists('academic_years', 'id')->where('school_id', $school->id),
            ],
            'semester' => ['required', Rule::in([1, 2])],
            'class_level_id' => [
                'nullable',
                'uuid',
                Rule::exists('class_levels', 'id')->where('school_id', $school->id),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'academic_year_id.required' => 'Tahun ajaran wajib dipilih.',
            'academic_year_id.uuid' => 'Tahun ajaran tidak valid.',
            'academic_year_id.exists' => 'Tahun ajaran tidak ditemukan untuk pesantren ini.',
            'semester.required' => 'Semester wajib dipilih.',
            'semester.in' => 'Semester harus 1 atau 2.',
            'class_level_id.uuid' => 'Kelas tidak valid.',
            'class_level_id.exists' => 'Kelas tidak ditemukan untuk pesantren ini.',
        ];
    }
}
