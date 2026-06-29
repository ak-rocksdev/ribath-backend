<?php

namespace Database\Factories;

use App\Models\StudentFeeAssignment;
use App\Models\StudentFeeException;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class StudentFeeExceptionFactory extends Factory
{
    protected $model = StudentFeeException::class;

    public function definition(): array
    {
        return [
            'student_fee_assignment_id' => StudentFeeAssignment::factory(),
            'kind' => StudentFeeException::KIND_PARTIAL_NOMINAL,
            'discount_amount' => 100000,
            'reason' => $this->faker->sentence(),
            'effective_from' => now('Asia/Jakarta')->startOfMonth()->toDateString(),
            'effective_until' => null,
            'created_by' => User::factory(),
        ];
    }

    public function forAssignment(StudentFeeAssignment $assignment): self
    {
        return $this->state(fn () => ['student_fee_assignment_id' => $assignment->id]);
    }

    public function fullWaiver(): self
    {
        return $this->state(fn () => [
            'kind' => StudentFeeException::KIND_FULL_WAIVER,
            'discount_amount' => null,
        ]);
    }

    public function partialDiscount(int $amount): self
    {
        return $this->state(fn () => [
            'kind' => StudentFeeException::KIND_PARTIAL_NOMINAL,
            'discount_amount' => $amount,
        ]);
    }

    public function effectiveFrom(string $date): self
    {
        return $this->state(fn () => ['effective_from' => $date]);
    }

    public function effectiveUntil(?string $date): self
    {
        return $this->state(fn () => ['effective_until' => $date]);
    }
}
