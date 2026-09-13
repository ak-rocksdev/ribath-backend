<?php

namespace App\Http\Requests\Akademik;

use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /attendance-alerts?academic_year_id&semester — both optional
 * together; omitting both falls back to the active academic year and its
 * active_semester (MissingSessionFinder).
 */
class ListAttendanceAlertsRequest extends FormRequest
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
                'nullable', 'required_with:semester', 'uuid',
                Rule::exists('academic_years', 'id')->where('school_id', $school->id),
            ],
            'semester' => ['nullable', 'required_with:academic_year_id', Rule::in([1, 2])],
        ];
    }

    public function messages(): array
    {
        return [
            'academic_year_id.uuid' => 'Tahun ajaran tidak valid.',
            'academic_year_id.exists' => 'Tahun ajaran tidak ditemukan untuk pesantren ini.',
            'academic_year_id.required_with' => 'Tahun ajaran wajib diisi jika semester diisi.',
            'semester.in' => 'Semester harus 1 atau 2.',
            'semester.required_with' => 'Semester wajib diisi jika tahun ajaran diisi.',
        ];
    }
}
