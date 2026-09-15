<?php

namespace App\Http\Requests\Akademik;

use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query of GET /report-cards: one class in one semester akademik, every id
 * scoped to the active school.
 */
class ListReportCardsRequest extends FormRequest
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
            'class_level_id' => ['required', 'uuid', Rule::exists('class_levels', 'id')->where('school_id', $school->id)],
        ];
    }

    public function messages(): array
    {
        return array_merge(ReportCardRules::semesterMessages(), [
            'class_level_id.required' => 'Kelas wajib dipilih.',
            'class_level_id.uuid' => 'Kelas tidak valid.',
            'class_level_id.exists' => 'Kelas tidak ditemukan untuk pesantren ini.',
        ]);
    }
}
