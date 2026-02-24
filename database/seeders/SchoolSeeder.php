<?php

namespace Database\Seeders;

use App\Models\Registration;
use App\Models\RegistrationPeriod;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;

class SchoolSeeder extends Seeder
{
    public function run(): void
    {
        $defaultSchool = School::firstOrCreate(
            ['name' => 'Ribath Masjid Riyadh Solo'],
            [
                'address' => 'Solo, Jawa Tengah',
                'is_active' => true,
            ]
        );

        User::whereNull('school_id')->update(['school_id' => $defaultSchool->id]);
        Student::whereNull('school_id')->update(['school_id' => $defaultSchool->id]);
        Registration::whereNull('school_id')->update(['school_id' => $defaultSchool->id]);
        RegistrationPeriod::whereNull('school_id')->update(['school_id' => $defaultSchool->id]);
    }
}
