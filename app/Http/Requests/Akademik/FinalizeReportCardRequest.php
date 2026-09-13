<?php

namespace App\Http\Requests\Akademik;

use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Body of POST /report-cards/finalize: the santri and the semester
 * akademik, every id scoped to the active school.
 */
class FinalizeReportCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $school = School::activeOrFail();

        return [
            'student_id' => [
                'required',
                'uuid',
                Rule::exists('students', 'id')->where('school_id', $school->id)->whereNull('deleted_at'),
            ],
            'academic_year_id' => ['required', 'uuid', Rule::exists('academic_years', 'id')->where('school_id', $school->id)],
            'semester' => ['required', Rule::in([1, 2])],
        ];
    }

    public function messages(): array
    {
        return array_merge(ReportCardRules::semesterMessages(), [
            'student_id.required' => 'Santri wajib dipilih.',
            'student_id.uuid' => 'Santri tidak valid.',
            'student_id.exists' => 'Santri tidak ditemukan untuk pesantren ini.',
        ]);
    }
}
