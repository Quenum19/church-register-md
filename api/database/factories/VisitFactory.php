<?php

namespace Database\Factories;

use App\Enums\ReturnReason;
use App\Enums\VisitReason;
use App\Models\Visit;
use App\Models\Visitor;
use App\Services\FamilyRotationService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Visite de test. N'appelle PAS VisitorStatusService : utiliser les états de VisitorFactory
 * (prospect(), recurrent()…) pour des données cohérentes, ou appeler refresh() soi-même.
 *
 * États : first(), second(), third(), step(int), onDate(date), withoutFamily().
 *
 * @extends Factory<Visit>
 */
class VisitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'visitor_id' => Visitor::factory(),
            'visit_number' => 1,
            'visit_date' => CarbonImmutable::today()->toDateString(),
            'family_id' => fn (array $attributes): ?int => $this->familyIdFor($attributes['visit_date']),
            'answers' => [],
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    public function step(int $number): static
    {
        return match ($number) {
            1 => $this->first(),
            2 => $this->second(),
            default => $this->third(),
        };
    }

    /**
     * 1re visite : les réponses sont portées par le visiteur, `answers` est vide.
     */
    public function first(): static
    {
        return $this->state(fn (): array => ['visit_number' => 1, 'answers' => []]);
    }

    public function second(): static
    {
        return $this->state(function (): array {
            /** @var list<string> $reasons */
            $reasons = fake()->randomElements(ReturnReason::values(), fake()->numberBetween(1, 3));

            return [
                'visit_number' => 2,
                'answers' => [
                    'return_reasons' => $reasons,
                    'return_reasons_other' => in_array(ReturnReason::Autres->value, $reasons, true) ? 'La prière du jeudi' : null,
                ],
            ];
        });
    }

    public function third(): static
    {
        return $this->state(function (): array {
            $reason = fake()->randomElement(VisitReason::cases());

            return [
                'visit_number' => 3,
                'answers' => [
                    'visit_reason' => $reason->value,
                    'visit_reason_other' => $reason === VisitReason::Autres ? 'Rapprochement familial' : null,
                ],
            ];
        });
    }

    /**
     * Date de visite (la famille est recalculée d'après la rotation de ce mois).
     */
    public function onDate(CarbonInterface|string $date): static
    {
        $date = $date instanceof CarbonInterface ? $date->toDateString() : $date;

        return $this->state(fn (): array => [
            'visit_date' => $date,
            'family_id' => $this->familyIdFor($date),
        ]);
    }

    public function withoutFamily(): static
    {
        return $this->state(fn (): array => ['family_id' => null]);
    }

    private function familyIdFor(mixed $date): ?int
    {
        $date = $date instanceof CarbonInterface ? $date : CarbonImmutable::parse((string) $date);

        return app(FamilyRotationService::class)->familyFor($date)?->id;
    }
}
