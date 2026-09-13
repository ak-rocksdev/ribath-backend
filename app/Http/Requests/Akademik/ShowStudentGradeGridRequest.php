<?php

namespace App\Http\Requests\Akademik;

use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;

class ShowStudentGradeGridRequest extends FormRequest
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
