<?php

namespace Database\Factories;

use App\Models\Bill;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentFeeAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

class BillFactory extends Factory
{
    protected $model = Bill::class;

    public function definition(): array
    {
        $start = $this->faker->dateTimeBetween('-2 months', 'now')->format('Y-m-01');
        $end = date('Y-m-t', strtotime($start));

        return [
            'school_id' => School::factory(),
            'student_id' => Student::factory(),
            'student_fee_assignment_id' => StudentFeeAssignment::factory(),
            'billing_period_start' => $start,
            'billing_period_end' => $end,
            'cadence_at_generation' => 'monthly',
            'expected_amount' => 500000,
            'paid_amount' => 0,
            'status' => Bill::STATUS_PENDING,
            'due_date' => date('Y-m-d', strtotime($start.' +9 days')),
        ];
    }

    public function pending(): self
    {
        return $this->state(fn () => ['status' => Bill::STATUS_PENDING, 'paid_amount' => 0]);
    }

    public function partial(int $paid): self
    {
        return $this->state(fn () => ['status' => Bill::STATUS_PARTIAL, 'paid_amount' => $paid]);
    }

    public function paid(): self
    {
        return $this->state(fn (array $attrs) => [
            'status' => Bill::STATUS_PAID,
            'paid_amount' => $attrs['expected_amount'] ?? 500000,
        ]);
    }

    public function waived(): self
    {
        return $this->state(fn () => ['status' => Bill::STATUS_WAIVED, 'expected_amount' => 0, 'paid_amount' => 0]);
    }

    public function forStudent(Student $student): self
    {
        return $this->state(fn () => [
            'school_id' => $student->school_id,
            'student_id' => $student->id,
        ]);
    }

    public function forAssignment(StudentFeeAssignment $assignment): self
    {
        return $this->state(fn () => [
            'school_id' => $assignment->school_id,
            'student_id' => $assignment->student_id,
            'student_fee_assignment_id' => $assignment->id,
            'cadence_at_generation' => $assignment->cadence,
            'expected_amount' => $assignment->locked_amount,
        ]);
    }
}
