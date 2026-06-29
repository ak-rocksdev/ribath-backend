<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

// R3 override feature was rolled back same-day (2026-05-26) after stakeholder
// confirmed perubahan biaya terjadi per AY, bukan per gelombang. The original
// create migration is kept for history; this one drops the table forward-fix
// style. See memory: project_psb_period_fee_override_rolled_back.md.
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('registration_period_fee_overrides');
    }

    public function down(): void
    {
        // Intentionally not reversible — to bring the table back, revive R3
        // commits (see memory note for SHAs) rather than running this `down`.
    }
};
