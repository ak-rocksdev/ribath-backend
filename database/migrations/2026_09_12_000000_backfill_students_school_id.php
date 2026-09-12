<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill students.school_id for rows created through the manual
     * "Tambah Santri" form before StudentService started assigning it.
     * Those rows were invisible to every tenancy-guarded endpoint — fee
     * assignments, bills and payments all returned 404 for them.
     *
     * Single-tenant today: assigns the one active school. When there isn't
     * exactly one active school we skip instead of guessing.
     *
     * Data only — no schema change. Includes soft-deleted rows so a later
     * restore works. down() is intentionally a no-op: backfilled rows can't be
     * told apart afterwards, and re-orphaning them would re-break fees.
     */
    public function up(): void
    {
        $activeSchoolIds = DB::table('schools')->where('is_active', true)->pluck('id');

        if ($activeSchoolIds->count() !== 1) {
            return;
        }

        DB::table('students')
            ->whereNull('school_id')
            ->update(['school_id' => $activeSchoolIds->first()]);
    }

    public function down(): void
    {
        // Intentionally empty — see up().
    }
};
