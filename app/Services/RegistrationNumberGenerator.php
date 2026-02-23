<?php

namespace App\Services;

use App\Models\Registration;

class RegistrationNumberGenerator
{
    public function generate(): string
    {
        $year = now()->year;
        $prefix = "PSB-{$year}-";

        $lastNumber = Registration::withTrashed()
            ->where('registration_number', 'like', "{$prefix}%")
            ->orderBy('registration_number', 'desc')
            ->value('registration_number');

        $nextSequence = 1;
        if ($lastNumber) {
            $nextSequence = (int) substr($lastNumber, strrpos($lastNumber, '-') + 1) + 1;
        }

        return $prefix.str_pad($nextSequence, 5, '0', STR_PAD_LEFT);
    }
}
