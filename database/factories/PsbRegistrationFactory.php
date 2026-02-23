<?php

namespace Database\Factories;

use App\Models\PsbRegistration;
use Illuminate\Database\Eloquent\Factories\Factory;

class PsbRegistrationFactory extends Factory
{
    protected $model = PsbRegistration::class;

    public function definition(): array
    {
        return [
            'registration_number' => 'PSB-' . now()->year . '-' . str_pad(fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'status' => PsbRegistration::STATUS_BARU,
            'registrant_type' => 'wali',
            'nama_lengkap' => fake()->name(),
            'tempat_lahir' => fake()->city(),
            'tanggal_lahir' => fake()->dateTimeBetween('-20 years', '-5 years')->format('Y-m-d'),
            'jenis_kelamin' => fake()->randomElement(['L', 'P']),
            'program_minat' => fake()->randomElement(['tahfidz', 'regular']),
            'nama_wali' => fake()->name(),
            'no_hp_wali' => '08' . fake()->numerify('##########'),
            'email_wali' => fake()->safeEmail(),
            'sumber_info' => fake()->randomElement(['sosial_media', 'website', 'teman_keluarga', 'alumni', 'masjid', 'brosur', 'google', 'lainnya']),
        ];
    }

    public function selfRegistered(): static
    {
        return $this->state([
            'registrant_type' => 'santri',
            'nama_wali' => null,
            'email_wali' => null,
        ]);
    }
}
