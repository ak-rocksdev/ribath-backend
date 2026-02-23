<?php

namespace Database\Factories;

use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

class StudentFactory extends Factory
{
    protected $model = Student::class;

    public function definition(): array
    {
        return [
            'full_name' => $this->faker->name(),
            'birth_place' => $this->faker->city(),
            'birth_date' => $this->faker->date(),
            'gender' => $this->faker->randomElement(['L', 'P']),
            'program' => $this->faker->randomElement(['tahfidz', 'regular']),
            'status' => 'active',
            'entry_date' => $this->faker->date(),
            'class_level' => $this->faker->randomElement(Student::CLASS_LEVELS),
            'address' => $this->faker->address(),
            'notes' => $this->faker->optional()->sentence(),
        ];
    }

    public function graduated(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Student::STATUS_GRADUATED,
        ]);
    }

    public function transferred(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Student::STATUS_TRANSFERRED,
        ]);
    }

    public function withdrawn(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Student::STATUS_WITHDRAWN,
        ]);
    }
}
