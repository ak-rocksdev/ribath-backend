<?php

namespace Database\Factories;

use App\Models\Bill;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class StudentPaymentFactory extends Factory
{
    protected $model = StudentPayment::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'student_id' => Student::factory(),
            'bill_id' => Bill::factory(),
            'amount' => 500000,
            'payment_date' => now('Asia/Jakarta')->toDateString(),
            'payment_method' => StudentPayment::METHOD_TRANSFER,
            'cash_book_entry_id' => null,
            'proof_file_path' => null,
            'proof_file_mime' => null,
            'notes' => null,
            'confirmed_by' => User::factory(),
            'confirmed_at' => now(),
        ];
    }

    public function forBill(Bill $bill): self
    {
        return $this->state(fn () => [
            'school_id' => $bill->school_id,
            'student_id' => $bill->student_id,
            'bill_id' => $bill->id,
            'amount' => $bill->expected_amount,
        ]);
    }

    public function withProof(): self
    {
        return $this->state(fn () => [
            'proof_file_path' => 'private/student_payments/test/'.$this->faker->uuid().'.jpg',
            'proof_file_mime' => 'image/jpeg',
        ]);
    }
}
