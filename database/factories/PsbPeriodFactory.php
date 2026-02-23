<?php

namespace Database\Factories;

use App\Models\PsbPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

class PsbPeriodFactory extends Factory
{
    protected $model = PsbPeriod::class;

    public function definition(): array
    {
        $year = now()->year;

        return [
            'name' => "Pendaftaran {$year}/" . ($year + 1),
            'year' => "{$year}/" . ($year + 1),
            'gelombang' => 1,
            'pendaftaran_buka' => now(),
            'pendaftaran_tutup' => now()->addMonths(2),
            'tanggal_masuk' => now()->addMonths(3)->toDateString(),
            'biaya_pendaftaran' => 500000,
            'biaya_spp_bulanan' => 1000000,
            'kuota_santri' => 30,
            'kuota_terisi' => 0,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function full(): static
    {
        return $this->state(fn (array $attributes) => [
            'kuota_terisi' => $attributes['kuota_santri'],
        ]);
    }
}
