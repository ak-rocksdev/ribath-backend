<?php

namespace App\Http\Requests\Tahfidz;

use App\Http\Requests\Akademik\StudentGradeGridRules;
use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /mentored-students?academic_year_id&semester — the santri bimbingan
 * of a Semester Akademik in the active school.
 */
class ListMentoredStudentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return StudentGradeGridRules::semesterSelectionRules(School::activeOrFail());
    }

    public function messages(): array
    {
        return [
            'academic_year_id.required' => 'Tahun ajaran wajib dipilih.',
            'academic_year_id.uuid' => 'Tahun ajaran tidak valid.',
            'academic_year_id.exists' => 'Tahun ajaran tidak ditemukan untuk pesantren ini.',
            'semester.required' => 'Semester wajib dipilih.',
            'semester.in' => 'Semester harus 1 atau 2.',
        ];
    }
}
