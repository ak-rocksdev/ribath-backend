<?php

namespace App\Http\Requests\Akademik;

use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /attendance-schedules?academic_year_id&semester — the active Jadwal
 * Mengajar of a semester a Pertemuan can be recorded for, narrowed to the
 * Cakupan Mengajar of a "milik sendiri" user (ClassSessionService).
 */
class ListAttendanceSchedulesRequest extends FormRequest
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
