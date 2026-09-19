<?php

namespace App\Http\Requests\Admin;

use App\Models\TeachingSchedule;
use App\Traits\EnsuresActiveSchoolTenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /teaching-schedules/{teachingSchedule}. Tenancy is checked here,
 * before validation, so a schedule of another school answers 404 and is
 * never changed — nor recorded in the riwayat pengajar.
 *
 * Its Kelas are sent as `class_level_ids` (ADR 0006); the older single
 * `class_level_id` is still read as a list of one. Leaving both out keeps
 * the Kelas the schedule has.
 *
 * The Semester Akademik of a schedule is fixed: the edit form re-sends the
 * schedule's own year and semester, and any other value is refused (a
 * schedule reaches another semester by being cloned there), so every
 * riwayat pengajar entry stays in the semester its schedule lives in.
 */
class UpdateTeachingScheduleRequest extends FormRequest
{
    use EnsuresActiveSchoolTenancy, NormalizesTeachingScheduleClassLevels;

    public const MESSAGE_SEMESTER_AKADEMIK_IS_FIXED = 'Semester Akademik jadwal tidak dapat diubah; salin jadwal ke semester lain.';

    public function authorize(): bool
    {
        $teachingSchedule = $this->route('teachingSchedule');

        if ($teachingSchedule) {
            $this->ensureBelongsToActiveSchool($teachingSchedule);
        }

        return true;
    }

    public function rules(): array
    {
        /** @var TeachingSchedule $teachingSchedule */
        $teachingSchedule = $this->route('teachingSchedule');

        return [
            'academic_year_id' => ['sometimes', 'uuid', Rule::in([$teachingSchedule->academic_year_id])],
            'semester' => ['sometimes', 'integer', Rule::in([$teachingSchedule->semester])],
            'day_of_week' => ['sometimes', 'string', Rule::in(TeachingSchedule::DAYS_OF_WEEK)],
            'time_slot_id' => ['sometimes', 'uuid', 'exists:time_slots,id'],
            'class_level_ids' => ['sometimes', 'array', 'min:1'],
            'class_level_ids.*' => ['uuid', 'distinct', 'exists:class_levels,id'],
            'subject_book_id' => ['sometimes', 'uuid', 'exists:subject_books,id'],
            'teacher_id' => ['sometimes', 'uuid', 'exists:teachers,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return array_merge(self::CLASS_LEVEL_MESSAGES, [
            'academic_year_id.in' => self::MESSAGE_SEMESTER_AKADEMIK_IS_FIXED,
            'semester.in' => self::MESSAGE_SEMESTER_AKADEMIK_IS_FIXED,
        ]);
    }
}
