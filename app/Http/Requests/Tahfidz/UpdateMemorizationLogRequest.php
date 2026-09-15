<?php

namespace App\Http\Requests\Tahfidz;

use App\Models\MemorizationLog;
use App\Models\School;
use App\Models\Teacher;
use App\Traits\EnsuresActiveSchoolTenancy;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /memorization-logs/{memorizationLog}. student_id, academic_year_id
 * and semester are fixed at creation (same convention as ClassTask and
 * MemorizationTarget) — only teacher_id, log_date, type and the
 * Halaman/kualitas/notes fields can change. Tenancy is checked here
 * (authorize()), before the service can act on a foreign row.
 */
class UpdateMemorizationLogRequest extends FormRequest
{
    use EnsuresActiveSchoolTenancy;

    public function authorize(): bool
    {
        $memorizationLog = $this->route('memorizationLog');

        if ($memorizationLog) {
            $this->ensureBelongsToActiveSchool($memorizationLog);
        }

        return true;
    }

    public function rules(): array
    {
        $school = School::activeOrFail();

        return [
            'teacher_id' => [
                'sometimes',
                'required',
                'uuid',
                Rule::exists('teachers', 'id')
                    ->where('school_id', $school->id)
                    ->where('status', Teacher::STATUS_ACTIVE),
            ],
            'log_date' => ['sometimes', 'required', 'date'],
            'type' => ['sometimes', 'required', Rule::in([MemorizationLog::TYPE_NEW, MemorizationLog::TYPE_REVIEW])],
            'juz' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:30'],
            'start_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:604'],
            'end_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:604'],
            'pages' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'material_note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'quality_score' => ['sometimes', 'required', 'integer', 'min:0', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string'],
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
