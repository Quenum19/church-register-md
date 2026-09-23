<?php

namespace Database\Factories;

use App\Models\Family;
use App\Models\ReportRecipient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Par défaut : destinataire global (family_id NULL = tous les rapports).
 *
 * @extends Factory<ReportRecipient>
 */
class ReportRecipientFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'family_id' => null,
            'name' => fake()->firstName().' '.fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'active' => true,
        ];
    }

    public function forFamily(Family $family): static
    {
        return $this->state(fn (): array => ['family_id' => $family->id]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['active' => false]);
    }
}
