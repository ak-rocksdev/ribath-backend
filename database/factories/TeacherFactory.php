<?php

namespace Database\Factories;

use App\Models\School;
use App\Models\Teacher;
use Illuminate\Database\Eloquent\Factories\Factory;

class TeacherFactory extends Factory
{
    protected $model = Teacher::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            // unique(): teachers.code is unique per school, and a test often creates several.
            'code' => $this->faker->unique()->regexify('[A-Z]{2,3}[0-9]?'),
            'full_name' => $this->faker->name(),
            'status' => Teacher::STATUS_ACTIVE,
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => $this->faker->phoneNumber(),
            'notes' => $this->faker->optional()->sentence(),
        ];
    }

    public function onLeave(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Teacher::STATUS_ON_LEAVE,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Teacher::STATUS_INACTIVE,
        ]);
    }
}
