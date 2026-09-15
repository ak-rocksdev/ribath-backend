<?php

namespace App\Http\Requests\Akademik;

use App\Models\School;
use Illuminate\Validation\Rule;

/**
 * Rules shared by the grade grid read (query string) and bulk write (body):
 * the (academic_year_id, semester, class_level_id, subject_book_id)
 * selection, every id scoped to the active school.
 */
final class StudentGradeGridRules
{
    /**
     * The required (academic_year_id, semester) pair — the semester akademik
     * selection every semester-scoped request starts from.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function semesterSelectionRules(School $school): array
    {
        return [
            'academic_year_id' => [
                'required',
                'uuid',
                Rule::exists('academic_years', 'id')->where('school_id', $school->id),
            ],
            'semester' => ['required', Rule::in([1, 2])],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function gridSelectionRules(School $school): array
    {
        return [
            ...self::semesterSelectionRules($school),
            'class_level_id' => [
                'required',
                'uuid',
                Rule::exists('class_levels', 'id')->where('school_id', $school->id),
            ],
            'subject_book_id' => [
                'required',
                'uuid',
                Rule::exists('subject_books', 'id')->where('school_id', $school->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function gridSelectionMessages(): array
    {
        return [
            'academic_year_id.required' => 'Tahun ajaran wajib dipilih.',
            'academic_year_id.uuid' => 'Tahun ajaran tidak valid.',
            'academic_year_id.exists' => 'Tahun ajaran tidak ditemukan untuk pesantren ini.',
            'semester.required' => 'Semester wajib dipilih.',
            'semester.in' => 'Semester harus 1 atau 2.',
            'class_level_id.required' => 'Kelas wajib dipilih.',
            'class_level_id.uuid' => 'Kelas tidak valid.',
            'class_level_id.exists' => 'Kelas tidak ditemukan untuk pesantren ini.',
            'subject_book_id.required' => 'Kitab wajib dipilih.',
            'subject_book_id.uuid' => 'Kitab tidak valid.',
            'subject_book_id.exists' => 'Kitab tidak ditemukan untuk pesantren ini.',
        ];
    }
}
