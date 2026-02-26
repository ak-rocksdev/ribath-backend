<?php

namespace Database\Factories;

use App\Models\ClassLevel;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClassLevelFactory extends Factory
{
    protected $model = ClassLevel::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'slug' => $this->faker->unique()->lexify('????_????'),
            'label' => $this->faker->words(2, true),
            'category' => $this->faker->randomElement(['akademik', 'tahfidz', 'takhassus']),
            'sort_order' => $this->faker->numberBetween(1, 20),
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
