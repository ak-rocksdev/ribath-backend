<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill users.school_id for accounts created before account creation
     * (Akun Pengguna, Beri Akses) started assigning it. Akun Pengguna lists
     * and counts only the accounts of the active school, so those rows
     * vanished from the list and the summary.
     *
     * Single-tenant today: assigns the one active school. When there isn't
     * exactly one active school we skip instead of guessing — the same rule
     * as 2026_09_12_000000_backfill_students_school_id.
     *
     * Data only — no schema change. Includes soft-deleted rows so a later
     * restore works. down() is intentionally a no-op: backfilled rows can't be
     * told apart afterwards.
     */
    public function up(): void
    {
        $activeSchoolIds = DB::table('schools')->where('is_active', true)->pluck('id');

        if ($activeSchoolIds->count() !== 1) {
            return;
        }

        DB::table('users')
            ->whereNull('school_id')
            ->update(['school_id' => $activeSchoolIds->first()]);
    }

    public function down(): void
    {
        // Intentionally empty — see up().
    }
};
