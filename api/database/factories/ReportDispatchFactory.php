<?php

namespace Database\Factories;

use App\Models\ReportDispatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Par défaut : envoi automatique (sent_by NULL) du rapport du mois précédent.
 *
 * @extends Factory<ReportDispatch>
 */
class ReportDispatchFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $month = now()->startOfMonth()->subMonthNoOverflow();

        return [
            'family_id' => null,
            'year' => (int) $month->year,
            'month' => (int) $month->month,
            'sent_at' => now(),
            'recipients' => [fake()->safeEmail()],
            'sent_by' => null,
        ];
    }

    public function forMonth(int $year, int $month): static
    {
        return $this->state(fn (): array => ['year' => $year, 'month' => $month]);
    }
}
