<?php

namespace App\Services;

use App\Models\PsbRegistration;

class RegistrationNumberGenerator
{
    public function generate(): string
    {
        $year = now()->year;
        $prefix = "PSB-{$year}-";

        $lastNumber = PsbRegistration::withTrashed()
            ->where('registration_number', 'like', "{$prefix}%")
            ->orderByRaw("CAST(SUBSTRING(registration_number FROM '[0-9]+$') AS INTEGER) DESC")
            ->value('registration_number');

        $nextSequence = 1;
        if ($lastNumber) {
            $nextSequence = (int) substr($lastNumber, strrpos($lastNumber, '-') + 1) + 1;
        }

        return $prefix . str_pad($nextSequence, 5, '0', STR_PAD_LEFT);
    }
}
