<?php

namespace App\Http\Requests\Tahfidz;

use App\Http\Requests\Akademik\StudentGradeGridRules;
use App\Models\MemorizationLog;
use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /memorization-logs?academic_year_id&semester[&student_id][&type][&date_from][&date_to]
 */
class ListMemorizationLogsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $school = School::activeOrFail();

        return [
            ...StudentGradeGridRules::semesterSelectionRules($school),
            'student_id' => [
                'nullable',
                'uuid',
                Rule::exists('students', 'id')->where('school_id', $school->id),
            ],
            'type' => ['nullable', Rule::in([MemorizationLog::TYPE_NEW, MemorizationLog::TYPE_REVIEW])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
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
            'student_id.uuid' => 'Santri tidak valid.',
            'student_id.exists' => 'Santri tidak ditemukan untuk pesantren ini.',
            'type.in' => 'Jenis setoran harus Setoran atau Murajaah.',
            'date_from.date' => 'Tanggal awal tidak valid.',
            'date_to.date' => 'Tanggal akhir tidak valid.',
            'date_to.after_or_equal' => 'Tanggal akhir harus setelah atau sama dengan tanggal awal.',
        ];
    }
}
