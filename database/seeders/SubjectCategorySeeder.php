<?php

namespace Database\Seeders;

use App\Models\School;
use App\Models\SubjectCategory;
use Illuminate\Database\Seeder;

class SubjectCategorySeeder extends Seeder
{
    public function run(): void
    {
        $school = School::activeOrFail();

        $categories = [
            ['slug' => 'nahwu',    'name' => 'Nahwu',    'color' => 'bg-blue-100',   'sort_order' => 1],
            ['slug' => 'shorof',   'name' => 'Shorof',   'color' => 'bg-indigo-100', 'sort_order' => 2],
            ['slug' => 'fiqh',     'name' => 'Fiqh',     'color' => 'bg-green-100',  'sort_order' => 3],
            ['slug' => 'tauhid',   'name' => 'Tauhid',   'color' => 'bg-yellow-100', 'sort_order' => 4],
            ['slug' => 'hadits',   'name' => 'Hadits',   'color' => 'bg-orange-100', 'sort_order' => 5],
            ['slug' => 'tafsir',   'name' => 'Tafsir',   'color' => 'bg-purple-100', 'sort_order' => 6],
            ['slug' => 'akhlak',   'name' => 'Akhlak',   'color' => 'bg-pink-100',   'sort_order' => 7],
            ['slug' => 'tarikh',   'name' => 'Tarikh',   'color' => 'bg-red-100',    'sort_order' => 8],
            ['slug' => 'balaghah', 'name' => 'Balaghah', 'color' => 'bg-teal-100',   'sort_order' => 9],
            ['slug' => 'lughah',   'name' => 'Lughah',   'color' => 'bg-cyan-100',   'sort_order' => 10],
        ];

        foreach ($categories as $category) {
            SubjectCategory::firstOrCreate(
                ['school_id' => $school->id, 'slug' => $category['slug']],
                array_merge($category, ['school_id' => $school->id])
            );
        }
    }
}
