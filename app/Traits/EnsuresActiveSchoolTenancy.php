<?php

namespace App\Traits;

use App\Models\School;
use Illuminate\Database\Eloquent\Model;

trait EnsuresActiveSchoolTenancy
{
    /**
     * Hide cross-tenant entities behind a 404 instead of 403 so attackers
     * cannot probe for existence of records belonging to other schools.
     */
    protected function ensureBelongsToActiveSchool(Model $model): void
    {
        $school = School::activeOrFail();

        abort_unless($model->school_id === $school->id, 404);
    }
}
