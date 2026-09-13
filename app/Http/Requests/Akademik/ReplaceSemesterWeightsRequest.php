<?php

namespace App\Http\Requests\Akademik;

use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReplaceSemesterWeightsRequest extends FormRequest
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
            'grading_template_id' => [
                'required',
                'uuid',
                Rule::exists('grading_templates', 'id')->where('school_id', $school->id),
            ],
            'factors' => ['required', 'array', 'min:1'],
            'factors.*.grading_factor_id' => [
                'required',
                'uuid',
                Rule::exists('grading_factors', 'id')->where('school_id', $school->id),
            ],
            // Decimal, 0-100, max 2 decimal places.
            'factors.*.weight' => ['required', 'numeric', 'min:0', 'max:100', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'factors.*.is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'academic_year_id.required' => 'Tahun ajaran wajib dipilih.',
            'academic_year_id.exists' => 'Tahun ajaran tidak ditemukan untuk pesantren ini.',
            'semester.required' => 'Semester wajib dipilih.',
            'semester.in' => 'Semester harus 1 atau 2.',
            'grading_template_id.required' => 'Template penilaian wajib dipilih.',
            'grading_template_id.exists' => 'Template penilaian tidak ditemukan untuk pesantren ini.',
            'factors.required' => 'Daftar faktor wajib diisi.',
            'factors.min' => 'Daftar faktor tidak boleh kosong.',
            'factors.*.grading_factor_id.required' => 'Faktor wajib dipilih.',
            'factors.*.grading_factor_id.exists' => 'Faktor tidak ditemukan untuk pesantren ini.',
            'factors.*.weight.required' => 'Bobot wajib diisi.',
            'factors.*.weight.numeric' => 'Bobot harus berupa angka.',
            'factors.*.weight.min' => 'Bobot tidak boleh negatif.',
            'factors.*.weight.max' => 'Bobot maksimal 100.',
            'factors.*.weight.regex' => 'Bobot maksimal 2 angka desimal.',
        ];
    }
}
