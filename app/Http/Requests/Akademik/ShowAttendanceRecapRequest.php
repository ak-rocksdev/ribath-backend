<?php

namespace App\Http\Requests\Akademik;

use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Query of GET /attendance-recaps: the same Kelas × Kitab selection as the
 * grade grid and grade recap, every id scoped to the active school. The
 * service checks the pair is scheduled and the semester is configured —
 * unlike the grade recap it does NOT require the kitab to have a grading
 * template, so a kitab without one may still show attendance.
 */
class ShowAttendanceRecapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return StudentGradeGridRules::gridSelectionRules(School::activeOrFail());
    }

    public function messages(): array
    {
        return StudentGradeGridRules::gridSelectionMessages();
    }
}
