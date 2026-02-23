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
        ];
    }
}
