<?php

namespace Database\Factories;

use App\Models\School;
use App\Models\SubjectCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubjectCategoryFactory extends Factory
{
    protected $model = SubjectCategory::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'slug' => fake()->unique()->slug(2),
            'name' => fake()->unique()->word(),
            'color' => 'bg-gray-100',
            'description' => fake()->optional()->sentence(),
            'sort_order' => fake()->numberBetween(0, 100),
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
