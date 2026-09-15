<?php

namespace App\Http\Requests\Akademik;

/**
 * Messages shared by the Rapor Form Requests.
 */
final class ReportCardRules
{
    public const REASON_MAX_LENGTH = 1000;

    /**
     * @return array<string, string>
     */
    public static function semesterMessages(): array
    {
        return [
            'academic_year_id.required' => 'Tahun ajaran wajib dipilih.',
            'academic_year_id.uuid' => 'Tahun ajaran tidak valid.',
            'academic_year_id.exists' => 'Tahun ajaran tidak ditemukan untuk pesantren ini.',
            'semester.required' => 'Semester wajib dipilih.',
            'semester.in' => 'Semester harus 1 atau 2.',
        ];
    }
}
