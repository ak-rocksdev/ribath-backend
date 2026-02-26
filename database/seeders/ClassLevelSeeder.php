<?php

namespace Database\Seeders;

use App\Models\ClassLevel;
use App\Models\School;
use Illuminate\Database\Seeder;

class ClassLevelSeeder extends Seeder
{
    public function run(): void
    {
        $defaultSchool = School::where('is_active', true)->first();

        if (! $defaultSchool) {
            return;
        }

        $classLevels = [
            ['slug' => 'tamhidi', 'label' => 'Tamhidi', 'category' => 'akademik', 'sort_order' => 1],
            ['slug' => 'ibtida_1', 'label' => 'Ibtida 1', 'category' => 'akademik', 'sort_order' => 2],
            ['slug' => 'ibtida_2', 'label' => 'Ibtida 2', 'category' => 'akademik', 'sort_order' => 3],
            ['slug' => 'tsanawiyah_1', 'label' => 'Tsanawiyah 1', 'category' => 'akademik', 'sort_order' => 4],
            ['slug' => 'tsanawiyah_2', 'label' => 'Tsanawiyah 2', 'category' => 'akademik', 'sort_order' => 5],
            ['slug' => 'tahfidz_1', 'label' => 'Tahfidz 1', 'category' => 'tahfidz', 'sort_order' => 6],
            ['slug' => 'tahfidz_2', 'label' => 'Tahfidz 2', 'category' => 'tahfidz', 'sort_order' => 7],
            ['slug' => 'tahfidz_3', 'label' => 'Tahfidz 3', 'category' => 'tahfidz', 'sort_order' => 8],
            ['slug' => 'takhassus_1', 'label' => 'Takhassus 1', 'category' => 'takhassus', 'sort_order' => 9],
            ['slug' => 'takhassus_2', 'label' => 'Takhassus 2', 'category' => 'takhassus', 'sort_order' => 10],
            ['slug' => 'takhassus_3', 'label' => 'Takhassus 3', 'category' => 'takhassus', 'sort_order' => 11],
        ];

        foreach ($classLevels as $classLevel) {
            ClassLevel::firstOrCreate(
                [
                    'school_id' => $defaultSchool->id,
                    'slug' => $classLevel['slug'],
                ],
                [
                    'label' => $classLevel['label'],
                    'category' => $classLevel['category'],
                    'sort_order' => $classLevel['sort_order'],
                ]
            );
        }
    }
}
