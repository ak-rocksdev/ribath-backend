<?php

namespace App\Http\Requests\Akademik;

use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Query of GET /grade-recaps/class: the same Kelas × Kitab selection as the
 * grade grid, every id scoped to the active school.
 */
class ShowClassGradeRecapRequest extends FormRequest
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
