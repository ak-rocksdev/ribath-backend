<?php

namespace App\Http\Requests\Admin;

use App\Models\TeachingSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /teaching-schedules. A schedule holds one Kelas or several (ADR
 * 0006), so the form sends `class_level_ids`; the older single
 * `class_level_id` — the file import still sends one Kelas per row — is
 * accepted and read as a list of one.
 */
class StoreTeachingScheduleRequest extends FormRequest
{
    use NormalizesTeachingScheduleClassLevels;

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
            'class_level_ids' => ['required', 'array', 'min:1'],
            'class_level_ids.*' => ['uuid', 'distinct', 'exists:class_levels,id'],
            'subject_book_id' => ['required', 'uuid', 'exists:subject_books,id'],
            'teacher_id' => ['required', 'uuid', 'exists:teachers,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return self::CLASS_LEVEL_MESSAGES;
    }
}
