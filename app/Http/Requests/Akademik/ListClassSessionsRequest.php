<?php

namespace App\Http\Requests\Akademik;

use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /class-sessions?academic_year_id&semester[&class_level_id][&teaching_schedule_id][&date_from][&date_to]
 */
class ListClassSessionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $school = School::activeOrFail();

        return [
            ...StudentGradeGridRules::semesterSelectionRules($school),
            'class_level_id' => ['nullable', 'uuid', Rule::exists('class_levels', 'id')->where('school_id', $school->id)],
            'teaching_schedule_id' => ['nullable', 'uuid', Rule::exists('teaching_schedules', 'id')->where('school_id', $school->id)],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ];
    }

    public function messages(): array
    {
        return [
            'academic_year_id.required' => 'Tahun ajaran wajib dipilih.',
            'academic_year_id.uuid' => 'Tahun ajaran tidak valid.',
            'academic_year_id.exists' => 'Tahun ajaran tidak ditemukan untuk pesantren ini.',
            'semester.required' => 'Semester wajib dipilih.',
            'semester.in' => 'Semester harus 1 atau 2.',
            'class_level_id.uuid' => 'Kelas tidak valid.',
            'class_level_id.exists' => 'Kelas tidak ditemukan untuk pesantren ini.',
            'teaching_schedule_id.uuid' => 'Jadwal mengajar tidak valid.',
            'teaching_schedule_id.exists' => 'Jadwal mengajar tidak ditemukan untuk pesantren ini.',
            'date_from.date_format' => 'Format tanggal awal harus YYYY-MM-DD.',
            'date_to.date_format' => 'Format tanggal akhir harus YYYY-MM-DD.',
            'date_to.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.',
        ];
    }
}
