<?php

namespace App\Http\Requests\Akademik;

use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /class-tasks?academic_year_id&semester&class_level_id&subject_book_id
 * — same selection shape as the grade grid, so it shares the same rules and
 * messages.
 */
class ListClassTasksRequest extends FormRequest
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
