<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\School;
use App\Models\SubjectBook;
use App\Models\Teacher;
use App\Models\TeachingSchedule;
use App\Models\TimeSlot;
use Illuminate\Database\Eloquent\Factories\Factory;

class TeachingScheduleFactory extends Factory
{
    protected $model = TeachingSchedule::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'semester' => fake()->randomElement([1, 2]),
            'day_of_week' => fake()->randomElement(TeachingSchedule::DAYS_OF_WEEK),
            'time_slot_id' => TimeSlot::factory(),
            'class_level_id' => ClassLevel::factory(),
            'subject_book_id' => SubjectBook::factory(),
            'teacher_id' => Teacher::factory(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
