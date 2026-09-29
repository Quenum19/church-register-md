<?php

namespace Database\Factories;

use App\Models\Event;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Événement de test. États : inactive(), onDate(date), withoutDate().
 *
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(1, 999999);

        return [
            'name' => 'Culte spécial '.$number,
            'slug' => 'culte-special-'.$number,
            'event_date' => fake()->dateTimeBetween('-6 months', '+6 months')->format('Y-m-d'),
            'active' => true,
            'created_by' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['active' => false]);
    }

    public function onDate(CarbonInterface|string $date): static
    {
        $date = $date instanceof CarbonInterface ? $date->toDateString() : $date;

        return $this->state(fn (): array => ['event_date' => $date]);
    }

    public function withoutDate(): static
    {
        return $this->state(fn (): array => ['event_date' => null]);
    }
}
