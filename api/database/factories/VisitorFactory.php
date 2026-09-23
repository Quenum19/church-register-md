<?php

namespace Database\Factories;

use App\Enums\Source;
use App\Models\Member;
use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use App\Services\VisitorStatusService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\Support\IvorianSamples;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Visiteurs de test. Par défaut : aucune visite (statut « prospect »).
 *
 * États par étape (visites cohérentes : numéros 1..n, dates croissantes, famille selon la rotation,
 * statut recalculé par VisitorStatusService) :
 *   prospect() = 1 visite, recurrent() = 2, membrePotentiel() = 3, member() = 3 + conversion.
 *   withVisits(int $count, ?CarbonInterface $firstVisit = null) pour un contrôle fin.
 *
 * @extends Factory<Visitor>
 */
class VisitorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        [$commune, $quartier] = IvorianSamples::place();
        $source = fake()->randomElement(Source::cases());

        return [
            'phone' => IvorianSamples::mobileE164(),
            'full_name' => IvorianSamples::fullName(),
            'whatsapp' => null,
            'commune' => $commune,
            'quartier' => $quartier,
            'source' => $source,
            'source_other' => $source === Source::Autre ? 'Invitation lors d\'un concert' : null,
            'invited_by' => $source === Source::InviteMembre ? IvorianSamples::fullName() : null,
            'inviter_family_id' => null,
            'wants_whatsapp_group' => false,
            'consent_at' => now(),
        ];
    }

    public function withWhatsapp(?string $e164 = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'whatsapp' => $e164 ?? $attributes['phone'],
            'wants_whatsapp_group' => true,
        ]);
    }

    /**
     * Crée `$count` visites (1 à 3) espacées d'une à quatre semaines, la première à `$firstVisit`
     * (par défaut : de façon à ce que la dernière tombe au plus tard aujourd'hui).
     */
    public function withVisits(int $count, ?CarbonInterface $firstVisit = null): static
    {
        $count = max(0, min(Visit::MAX_VISITS, $count));

        return $this->afterCreating(function (Visitor $visitor) use ($count, $firstVisit): void {
            $date = $firstVisit !== null
                ? CarbonImmutable::instance($firstVisit)->startOfDay()
                : CarbonImmutable::today()->subWeeks(4 * $count);

            // Le visiteur est enregistré lors de sa 1re visite.
            if ($count > 0) {
                $visitor->forceFill(['created_at' => $date->setTime(10, 0), 'updated_at' => $date->setTime(10, 0)])
                    ->saveQuietly();
            }

            for ($number = 1; $number <= $count; $number++) {
                Visit::factory()->step($number)->for($visitor)->onDate($date)->create();
                $date = $date->addWeeks(fake()->numberBetween(1, 4));
            }

            app(VisitorStatusService::class)->refresh($visitor);
        });
    }

    public function prospect(?CarbonInterface $firstVisit = null): static
    {
        return $this->withVisits(1, $firstVisit);
    }

    public function recurrent(?CarbonInterface $firstVisit = null): static
    {
        return $this->withVisits(2, $firstVisit);
    }

    public function membrePotentiel(?CarbonInterface $firstVisit = null): static
    {
        return $this->withVisits(3, $firstVisit);
    }

    /**
     * Trois visites puis conversion en membre (par `$by`, ou sans auteur).
     */
    public function member(?User $by = null, ?CarbonInterface $firstVisit = null): static
    {
        return $this->membrePotentiel($firstVisit)->afterCreating(function (Visitor $visitor) use ($by): void {
            Member::query()->create([
                'visitor_id' => $visitor->id,
                'converted_by' => $by?->id,
                'converted_at' => now(),
            ]);

            app(VisitorStatusService::class)->refresh($visitor);
        });
    }
}
