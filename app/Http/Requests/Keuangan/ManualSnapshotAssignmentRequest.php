<?php

namespace App\Http\Requests\Keuangan;

use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ManualSnapshotAssignmentRequest extends FormRequest
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
                'required', 'uuid',
                Rule::exists('academic_years', 'id')->where('school_id', $school->id),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'academic_year_id.required' => 'Tahun ajaran wajib dipilih.',
            'academic_year_id.exists' => 'Tahun ajaran tidak ditemukan untuk pesantren ini.',
        ];
    }
}
