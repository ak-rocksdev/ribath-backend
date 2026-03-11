<?php

namespace App\Http\Requests\Admin;

use App\Models\TeachingSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTeachingScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_year_id' => ['required', 'uuid', 'exists:academic_years,id'],
            'semester' => ['required', 'integer', 'in:1,2'],
            'day_of_week' => ['required', 'string', Rule::in(TeachingSchedule::DAYS_OF_WEEK)],
            'time_slot_id' => ['required', 'uuid', 'exists:time_slots,id'],
            'class_level_id' => ['required', 'uuid', 'exists:class_levels,id'],
            'subject_book_id' => ['required', 'uuid', 'exists:subject_books,id'],
            'teacher_id' => ['required', 'uuid', 'exists:teachers,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
