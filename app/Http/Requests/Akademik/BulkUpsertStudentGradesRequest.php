<?php

namespace App\Http\Requests\Akademik;

use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Structural validation of PUT /student-grades/bulk. Per-student and
 * per-cell checks (student in class, factor in template, score 0-100)
 * live in StudentGradeService::upsertGrid so their errors can be keyed
 * "<student_id>" / "<student_id>.<factor_code>" instead of row indexes.
 */
class BulkUpsertStudentGradesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge(StudentGradeGridRules::gridSelectionRules(School::activeOrFail()), [
            'rows' => ['required', 'array', 'min:1'],
            'rows.*' => ['array'],
            'rows.*.student_id' => ['required', 'uuid'],
            'rows.*.scores' => ['present', 'array'],
        ]);
    }

    public function messages(): array
    {
        return array_merge(StudentGradeGridRules::gridSelectionMessages(), [
            'rows.required' => 'Daftar nilai santri wajib diisi.',
            'rows.array' => 'Daftar nilai santri tidak valid.',
            'rows.min' => 'Daftar nilai santri tidak boleh kosong.',
            'rows.*.array' => 'Baris nilai santri tidak valid.',
            'rows.*.student_id.required' => 'Santri wajib diisi.',
            'rows.*.student_id.uuid' => 'Santri tidak valid.',
            'rows.*.scores.present' => 'Nilai santri wajib dikirim.',
            'rows.*.scores.array' => 'Nilai santri tidak valid.',
        ]);
    }
}
