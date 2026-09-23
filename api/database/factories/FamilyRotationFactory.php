<?php

namespace Database\Factories;

use App\Models\Family;
use App\Models\FamilyRotation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FamilyRotation>
 */
class FamilyRotationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'family_id' => Family::factory(),
            'year' => (int) now()->year,
            'month' => (int) now()->month,
        ];
    }

    public function forMonth(int $year, int $month): static
    {
        return $this->state(fn (): array => ['year' => $year, 'month' => $month]);
    }
}
