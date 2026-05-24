<?php

namespace Database\Seeders;

use App\Models\CashBookCategory;
use App\Models\School;
use Illuminate\Database\Seeder;

class CashBookDefaultCategoriesSeeder extends Seeder
{
    /**
     * Ensure every school has the system-protected "Saldo Awal" category so the
     * cash book opening-balance flow always has a target category. Idempotent —
     * safe to run repeatedly; existing rows are left untouched.
     */
    public function run(): void
    {
        foreach (School::all() as $school) {
            CashBookCategory::firstOrCreate(
                [
                    'school_id' => $school->id,
                    'name' => CashBookCategory::SYSTEM_NAME_SALDO_AWAL,
                ],
                [
                    'is_system' => true,
                    'is_active' => true,
                ]
            );
        }
    }
}
