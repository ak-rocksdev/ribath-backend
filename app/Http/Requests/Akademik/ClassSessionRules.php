<?php

namespace App\Http\Requests\Akademik;

use App\Models\School;
use Illuminate\Validation\Rule;

/**
 * Rules shared by the Pertemuan & Absensi requests. Every id in a body or
 * query string is scoped to the active school. Per-santri checks (in the
 * class, entered by the date, not duplicated, status/notes values,
 * completeness) live in ClassSessionService so their errors are keyed
 * "<student_id>" rather than by row index.
 */
final class ClassSessionRules
{
    /**
     * A schedule a session can be recorded or cancelled for: this school's
     * and still active (a deleted schedule is kept with is_active = false).
     *
     * @return array<int, mixed>
     */
    public static function activeTeachingScheduleRules(School $school): array
    {
        return [
            'required',
            'uuid',
            Rule::exists('teaching_schedules', 'id')
                ->where('school_id', $school->id)
                ->where('is_active', true),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function sessionDateRules(): array
    {
        return ['required', 'date_format:Y-m-d'];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function attendanceRowsRules(): array
    {
        return [
            'attendances' => ['required', 'array', 'min:1'],
            'attendances.*' => ['array'],
            'attendances.*.student_id' => ['required', 'uuid'],
            'attendances.*.status' => ['present'],
            'attendances.*.notes' => ['nullable'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'teaching_schedule_id.required' => 'Jadwal mengajar wajib dipilih.',
            'teaching_schedule_id.uuid' => 'Jadwal mengajar tidak valid.',
            'teaching_schedule_id.exists' => 'Jadwal mengajar tidak ditemukan atau tidak aktif.',
            'session_date.required' => 'Tanggal pertemuan wajib diisi.',
            'session_date.date_format' => 'Format tanggal pertemuan harus YYYY-MM-DD.',
            'attendances.required' => 'Daftar absensi santri wajib diisi.',
            'attendances.array' => 'Daftar absensi santri tidak valid.',
            'attendances.min' => 'Daftar absensi santri tidak boleh kosong.',
            'attendances.*.array' => 'Baris absensi santri tidak valid.',
            'attendances.*.student_id.required' => 'Santri wajib diisi.',
            'attendances.*.student_id.uuid' => 'Santri tidak valid.',
            'attendances.*.status.present' => 'Status absensi santri wajib dikirim.',
        ];
    }
}
