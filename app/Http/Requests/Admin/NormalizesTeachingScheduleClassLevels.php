<?php

namespace App\Http\Requests\Admin;

/**
 * The Kelas of a Jadwal Mengajar are a list (ADR 0006). Clients that know
 * only the single `class_level_id` — the file import, and any frontend not
 * deployed yet — keep working: their one Kelas becomes a list of one before
 * validation, so the rest of the request, the service and the error
 * messages speak of `class_level_ids` alone.
 */
trait NormalizesTeachingScheduleClassLevels
{
    /** @var array<string, string> */
    public const CLASS_LEVEL_MESSAGES = [
        'class_level_ids.required' => 'Pilih minimal satu kelas untuk jadwal ini.',
        'class_level_ids.min' => 'Pilih minimal satu kelas untuk jadwal ini.',
        'class_level_ids.*.distinct' => 'Kelas yang sama hanya boleh dipilih sekali.',
        'class_level_ids.*.exists' => 'Kelas yang dipilih tidak ditemukan.',
    ];

    protected function prepareForValidation(): void
    {
        if ($this->has('class_level_ids') || ! $this->filled('class_level_id')) {
            return;
        }

        $this->merge(['class_level_ids' => [$this->input('class_level_id')]]);
    }
}
