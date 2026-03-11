<?php

namespace Database\Seeders;

use App\Models\School;
use App\Models\TimeSlot;
use Illuminate\Database\Seeder;

class TimeSlotSeeder extends Seeder
{
    public function run(): void
    {
        $school = School::where('is_active', true)->firstOrFail();

        $slots = [
            ['code' => 'after_fajr',    'label' => "Ba'da Subuh",   'type' => 'prayer_based', 'start_time' => '05:45', 'end_time' => '06:45', 'sort_order' => 1],
            ['code' => '07:00-08:00',   'label' => '07:00 - 08:00', 'type' => 'fixed_clock',  'start_time' => '07:00', 'end_time' => '08:00', 'sort_order' => 2],
            ['code' => '08:00-09:00',   'label' => '08:00 - 09:00', 'type' => 'fixed_clock',  'start_time' => '08:00', 'end_time' => '09:00', 'sort_order' => 3],
            ['code' => 'after_dhuhr',   'label' => "Ba'da Dhuhur",  'type' => 'prayer_based', 'start_time' => '12:30', 'end_time' => '13:30', 'sort_order' => 4],
            ['code' => 'after_asr',     'label' => "Ba'da Ashar",   'type' => 'prayer_based', 'start_time' => '15:30', 'end_time' => '16:30', 'sort_order' => 5],
            ['code' => 'after_maghrib', 'label' => "Ba'da Maghrib", 'type' => 'prayer_based', 'start_time' => '18:00', 'end_time' => '19:00', 'sort_order' => 6],
            ['code' => 'after_isha',    'label' => "Ba'da Isya",    'type' => 'prayer_based', 'start_time' => '19:30', 'end_time' => '20:30', 'sort_order' => 7],
        ];

        foreach ($slots as $slot) {
            TimeSlot::firstOrCreate(
                ['school_id' => $school->id, 'code' => $slot['code']],
                array_merge($slot, ['school_id' => $school->id])
            );
        }
    }
}
