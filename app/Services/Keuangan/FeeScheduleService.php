<?php

namespace App\Services\Keuangan;

use App\Models\AcademicYear;
use App\Models\FeeSchedule;
use App\Models\FeeType;
use App\Models\School;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class FeeScheduleService
{
    public function listSchedules(array $filters): LengthAwarePaginator
    {
        $school = School::activeOrFail();

        $query = FeeSchedule::query()
            ->where('school_id', $school->id)
            ->with(FeeSchedule::EAGER_LOAD_RELATIONS);

        if (! empty($filters['academic_year_id'])) {
            $query->where('academic_year_id', $filters['academic_year_id']);
        }

        if (! empty($filters['fee_type_id'])) {
            $query->where('fee_type_id', $filters['fee_type_id']);
        }

        // Stable ordering for predictable UI: newest AY first, then fee_type
        // code within each AY so the same fee_type clusters together when
        // multiple AYs are visible at once.
        $query->orderByDesc('academic_year_id')->orderBy('fee_type_id');

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 50)));

        return $query->paginate($perPage);
    }

    public function createSchedule(array $data): FeeSchedule
    {
        $school = School::activeOrFail();

        $this->ensureFeeTypeBelongsToSchool($data['fee_type_id'], $school->id);
        $this->ensureAcademicYearBelongsToSchool($data['academic_year_id'], $school->id);

        $schedule = FeeSchedule::create([
            'school_id' => $school->id,
            'academic_year_id' => $data['academic_year_id'],
            'fee_type_id' => $data['fee_type_id'],
            'amount' => (int) $data['amount'],
            'cadence_override' => $data['cadence_override'] ?? null,
        ]);

        return $schedule->fresh()->load(FeeSchedule::EAGER_LOAD_RELATIONS);
    }

    public function updateSchedule(FeeSchedule $schedule, array $data): FeeSchedule
    {
        $attributes = [];

        foreach (['amount', 'cadence_override'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $field === 'amount' ? (int) $data[$field] : $data[$field];
            }
        }

        // (academic_year_id, fee_type_id) intentionally immutable to preserve
        // the unique pair semantics — admins create a new schedule instead.
        if (! empty($attributes)) {
            $schedule->fill($attributes);
            if ($schedule->isDirty()) {
                $schedule->save();
            }
        }

        return $schedule->fresh()->load(FeeSchedule::EAGER_LOAD_RELATIONS);
    }

    public function deleteSchedule(FeeSchedule $schedule): void
    {
        // US2 will introduce a guard here against student_fee_assignments that
        // were snapshotted from this (AY × fee_type). For now a schedule can
        // always be removed since no downstream rows reference it yet.
        $schedule->delete();
    }

    private function ensureFeeTypeBelongsToSchool(string $feeTypeId, string $schoolId): void
    {
        $exists = FeeType::query()
            ->whereKey($feeTypeId)
            ->where('school_id', $schoolId)
            ->exists();

        abort_unless($exists, 422, 'Jenis biaya tidak ditemukan untuk pesantren ini.');
    }

    private function ensureAcademicYearBelongsToSchool(string $academicYearId, string $schoolId): void
    {
        $exists = AcademicYear::query()
            ->whereKey($academicYearId)
            ->where('school_id', $schoolId)
            ->exists();

        abort_unless($exists, 422, 'Tahun ajaran tidak ditemukan untuk pesantren ini.');
    }
}
