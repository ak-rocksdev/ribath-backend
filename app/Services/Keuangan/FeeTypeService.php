<?php

namespace App\Services\Keuangan;

use App\Models\CashBookCategory;
use App\Models\FeeSchedule;
use App\Models\FeeType;
use App\Models\School;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class FeeTypeService
{
    public function listFeeTypes(array $filters): LengthAwarePaginator
    {
        $school = School::activeOrFail();

        $query = FeeType::query()
            ->where('school_id', $school->id)
            ->with(FeeType::EAGER_LOAD_RELATIONS)
            ->orderBy('code');

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 50)));

        return $query->paginate($perPage);
    }

    public function createFeeType(array $data): FeeType
    {
        $school = School::activeOrFail();

        $this->ensureCategoryBelongsToSchool($data['cash_book_category_id'], $school->id);

        $feeType = FeeType::create([
            'school_id' => $school->id,
            'code' => $data['code'],
            'label' => $data['label'],
            'default_cadence' => $data['default_cadence'],
            'cash_book_category_id' => $data['cash_book_category_id'],
            'is_active' => array_key_exists('is_active', $data)
                ? (bool) $data['is_active']
                : true,
        ]);

        return $feeType->fresh()->load(FeeType::EAGER_LOAD_RELATIONS);
    }

    public function updateFeeType(FeeType $feeType, array $data): FeeType
    {
        $school = School::activeOrFail();
        $attributes = [];

        // `code` is intentionally immutable post-create (FR-003); silently drop
        // it from the payload rather than 422'ing so old PATCH clients keep
        // working when they re-submit the original payload.
        foreach (['label', 'default_cadence', 'cash_book_category_id', 'is_active'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $field === 'is_active'
                    ? (bool) $data[$field]
                    : $data[$field];
            }
        }

        if (array_key_exists('cash_book_category_id', $attributes)) {
            $this->ensureCategoryBelongsToSchool($attributes['cash_book_category_id'], $school->id);
        }

        if (! empty($attributes)) {
            $feeType->fill($attributes);
            if ($feeType->isDirty()) {
                $feeType->save();
            }
        }

        return $feeType->fresh()->load(FeeType::EAGER_LOAD_RELATIONS);
    }

    public function deleteFeeType(FeeType $feeType): void
    {
        // Block delete while downstream rows still reference this fee_type so
        // we don't strand fee_schedules / future assignments. Admins should
        // toggle `is_active` instead — the model carries no soft-deletes by
        // design so a delete here is a hard delete.
        // Future US2 expands this guard to also include student_fee_assignments.
        $scheduleCount = FeeSchedule::where('fee_type_id', $feeType->id)->count();

        if ($scheduleCount > 0) {
            throw new ConflictHttpException(
                'Tidak dapat dihapus — masih dipakai tarif tahunan. Nonaktifkan saja.'
            );
        }

        $feeType->delete();
    }

    private function ensureCategoryBelongsToSchool(string $categoryId, string $schoolId): void
    {
        $exists = CashBookCategory::query()
            ->whereKey($categoryId)
            ->where('school_id', $schoolId)
            ->exists();

        abort_unless($exists, 422, 'Kategori Buku Kas tidak ditemukan untuk pesantren ini.');
    }
}
