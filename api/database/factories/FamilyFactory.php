<?php

namespace Database\Factories;

use App\Models\Family;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pour les 7 familles réelles, préférer le FamilySeeder (ordre et rotations).
 *
 * @extends Factory<Family>
 */
class FamilyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Famille '.fake()->unique()->word(),
            'position' => fake()->unique()->numberBetween(10, 250),
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['active' => false]);
    }
}
