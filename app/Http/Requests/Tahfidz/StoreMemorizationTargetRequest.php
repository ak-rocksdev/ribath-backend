<?php

namespace App\Http\Requests\Tahfidz;

use App\Http\Requests\Akademik\StudentGradeGridRules;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /memorization-targets. Structural validation only — semester akademik
 * configuration and per-semester uniqueness are checked in
 * MemorizationTargetService (they need a query beyond a simple Rule).
 */
class StoreMemorizationTargetRequest extends FormRequest
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
                'required',
                'uuid',
                Rule::exists('students', 'id')
                    ->where('school_id', $school->id)
                    ->where('status', Student::STATUS_ACTIVE),
            ],
            'target_pages' => ['required_without:target_juz', 'nullable', 'numeric', 'gt:0', 'max:604'],
            'target_juz' => ['required_without:target_pages', 'nullable', 'numeric', 'gt:0', 'max:30'],
            'teacher_id' => [
                'required',
                'uuid',
                Rule::exists('teachers', 'id')
                    ->where('school_id', $school->id)
                    ->where('status', Teacher::STATUS_ACTIVE),
            ],
            'notes' => ['nullable', 'string'],
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
            'student_id.required' => 'Santri wajib dipilih.',
            'student_id.uuid' => 'Santri tidak valid.',
            'student_id.exists' => 'Santri tidak ditemukan atau tidak berstatus aktif untuk pesantren ini.',
            'target_pages.required_without' => 'Isi target halaman atau target juz.',
            'target_pages.numeric' => 'Target halaman harus berupa angka.',
            'target_pages.gt' => 'Target halaman harus lebih dari 0.',
            'target_pages.max' => 'Target halaman maksimal 604 halaman (satu mushaf).',
            'target_juz.required_without' => 'Isi target halaman atau target juz.',
            'target_juz.numeric' => 'Target juz harus berupa angka.',
            'target_juz.gt' => 'Target juz harus lebih dari 0.',
            'target_juz.max' => 'Target juz maksimal 30.',
            'teacher_id.required' => 'Ustadz wajib dipilih.',
            'teacher_id.uuid' => 'Ustadz tidak valid.',
            'teacher_id.exists' => 'Ustadz tidak ditemukan atau tidak berstatus aktif untuk pesantren ini.',
            'notes.string' => 'Catatan tidak valid.',
        ];
    }
}
