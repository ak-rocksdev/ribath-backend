<?php

namespace Database\Seeders;

use App\Models\School;
use App\Services\Akademik\GradingDefaultsInstaller;
use Illuminate\Database\Seeder;

class GradingDefaultsSeeder extends Seeder
{
    /**
     * Installs the fixed grading templates/factors for the active school
     * and ensures every one of its existing academic_semesters has
     * grading_template_factors weights (idempotent — safe to re-run).
     */
    public function run(): void
    {
        $school = School::activeOrFail();

        $installer = app(GradingDefaultsInstaller::class);
        $installer->installForSchool($school);
        $installer->ensureWeightsForAllSemesters($school);
    }
}
