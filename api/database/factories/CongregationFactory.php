<?php

namespace Database\Factories;

use App\Models\Congregation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Les onze congrégations réelles sont créées par la migration (le déploiement ne lance que
 * `migrate --force`) : cette fabrique ne sert qu'aux cas de test.
 *
 * @extends Factory<Congregation>
 */
class CongregationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Congrégation '.fake()->unique()->word(),
            'position' => fake()->unique()->numberBetween(50, 250),
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['active' => false]);
    }
}
