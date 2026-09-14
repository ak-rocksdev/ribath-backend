<?php

namespace App\Http\Requests\Tahfidz;

use App\Http\Requests\Akademik\StudentGradeGridRules;
use App\Models\MemorizationLog;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /memorization-logs. Structural validation only — semester akademik
 * configuration, the Tahfizh kitab resolution, the log_date-within-semester
 * rule and the "pages must be a multiple of 0.5" rule are checked in
 * MemorizationLogService (they need queries or derived values beyond a
 * simple Rule). subject_book_id is never accepted from the client.
 */
class StoreMemorizationLogRequest extends FormRequest
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
            'teacher_id' => [
                'required',
                'uuid',
                Rule::exists('teachers', 'id')
                    ->where('school_id', $school->id)
                    ->where('status', Teacher::STATUS_ACTIVE),
            ],
            'log_date' => ['required', 'date'],
            'type' => ['required', Rule::in([MemorizationLog::TYPE_NEW, MemorizationLog::TYPE_REVIEW])],
            'juz' => ['nullable', 'integer', 'min:1', 'max:30'],
            'start_page' => ['nullable', 'integer', 'min:1', 'max:604'],
            'end_page' => ['nullable', 'integer', 'min:1', 'max:604'],
            'pages' => ['required_without_all:start_page,end_page', 'nullable', 'numeric', 'gt:0'],
            'material_note' => ['nullable', 'string', 'max:255'],
            'quality_score' => ['required', 'integer', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $startPage = $this->input('start_page');
            $endPage = $this->input('end_page');

            if ($startPage !== null && $endPage !== null && (int) $endPage < (int) $startPage) {
                $validator->errors()->add('end_page', 'Halaman akhir harus lebih besar atau sama dengan halaman awal.');
            }
        });
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
            'teacher_id.required' => 'Ustadz wajib dipilih.',
            'teacher_id.uuid' => 'Ustadz tidak valid.',
            'teacher_id.exists' => 'Ustadz tidak ditemukan atau tidak berstatus aktif untuk pesantren ini.',
            'log_date.required' => 'Tanggal setoran wajib diisi.',
            'log_date.date' => 'Tanggal setoran tidak valid.',
            'type.required' => 'Jenis setoran wajib dipilih.',
            'type.in' => 'Jenis setoran harus Setoran atau Murajaah.',
            'juz.integer' => 'Juz harus berupa angka.',
            'juz.min' => 'Juz minimal 1.',
            'juz.max' => 'Juz maksimal 30.',
            'start_page.integer' => 'Halaman awal harus berupa angka.',
            'start_page.min' => 'Halaman awal minimal 1.',
            'start_page.max' => 'Halaman awal maksimal 604.',
            'end_page.integer' => 'Halaman akhir harus berupa angka.',
            'end_page.min' => 'Halaman akhir minimal 1.',
            'end_page.max' => 'Halaman akhir maksimal 604.',
            'pages.required_without_all' => 'Isi jumlah halaman, atau halaman awal dan akhir.',
            'pages.numeric' => 'Jumlah halaman harus berupa angka.',
            'pages.gt' => 'Jumlah halaman harus lebih dari 0.',
            'material_note.string' => 'Catatan materi tidak valid.',
            'material_note.max' => 'Catatan materi maksimal 255 karakter.',
            'quality_score.required' => 'Nilai kualitas wajib diisi.',
            'quality_score.integer' => 'Nilai kualitas harus berupa angka.',
            'quality_score.min' => 'Nilai kualitas minimal 0.',
            'quality_score.max' => 'Nilai kualitas maksimal 100.',
            'notes.string' => 'Catatan tidak valid.',
        ];
    }
}
