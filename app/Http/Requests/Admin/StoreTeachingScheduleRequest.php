<?php

namespace App\Http\Requests\Admin;

use App\Models\TeachingSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /teaching-schedules. A schedule holds one Kelas or several (ADR
 * 0006), so every client sends `class_level_ids` — the file import too,
 * one Kelas per imported row.
 */
class StoreTeachingScheduleRequest extends FormRequest
{
    /**
     * What a bad Kelas list is told, here and on the edit request.
     *
     * @var array<string, string>
     */
    public const CLASS_LEVEL_MESSAGES = [
        'class_level_ids.required' => 'Pilih minimal satu kelas untuk jadwal ini.',
        'class_level_ids.min' => 'Pilih minimal satu kelas untuk jadwal ini.',
        'class_level_ids.*.distinct' => 'Kelas yang sama hanya boleh dipilih sekali.',
        'class_level_ids.*.exists' => 'Kelas yang dipilih tidak ditemukan.',
    ];

    /**
     * @deprecated Jendela deploy saja: SPA lama mengirim satu
     * `class_level_id`, diterima sebagai `class_level_ids: [id]`. Dihapus
     * pada rilis berikutnya, setelah frontend rilis.
     */
    protected function prepareForValidation(): void
    {
        $singleClassLevelId = $this->input('class_level_id');

        if ($singleClassLevelId !== null && $this->input('class_level_ids') === null) {
            $this->merge(['class_level_ids' => [$singleClassLevelId]]);
        }
    }

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
