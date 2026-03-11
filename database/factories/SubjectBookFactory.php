<?php

namespace Database\Factories;

use App\Models\School;
use App\Models\SubjectBook;
use App\Models\SubjectCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubjectBookFactory extends Factory
{
    protected $model = SubjectBook::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'subject_category_id' => SubjectCategory::factory(),
            'title' => fake()->unique()->words(2, true),
            'class_levels' => ['tamhidi'],
            'semesters' => [1],
            'sessions_per_week' => fake()->numberBetween(1, 5),
            'description' => fake()->optional()->sentence(),
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
