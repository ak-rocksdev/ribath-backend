<?php

namespace App\Http\Requests\Tahfidz;

use App\Models\School;
use App\Traits\EnsuresActiveSchoolTenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query of GET /students/{student}/memorization-progress: (academic_year_id,
 * semester), scoped to the active school. Tenancy on the route-bound
 * student is checked in authorize() — which always runs before rules() —
 * so a foreign student 404s before its query is validated at all.
 */
class ShowMemorizationProgressRequest extends FormRequest
{
    use EnsuresActiveSchoolTenancy;

    public function authorize(): bool
    {
        $student = $this->route('student');

        if ($student) {
            $this->ensureBelongsToActiveSchool($student);
        }

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
        ];
    }
}
