<?php

namespace Database\Seeders;

use App\Enums\Source;
use App\Models\Family;
use App\Models\FamilyRotation;
use App\Models\Member;
use App\Models\Visit;
use App\Models\Visitor;
use App\Services\VisitorStatusService;
use Carbon\CarbonImmutable;
use Database\Factories\Support\IvorianSamples;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Données de démonstration pour l'intégration du frontend (JAMAIS appelé par DatabaseSeeder).
 *
 *   php artisan db:seed --class=DemoSeeder
 *
 * ~60 visiteurs fictifs (noms ivoiriens, communes d'Abidjan) sur les 12 derniers mois, visites
 * le dimanche (1 à 3, dates croissantes, famille selon la rotation), quelques membres convertis
 * et deux nouveaux visiteurs aujourd'hui. Aucun compte utilisateur n'est créé.
 */
class DemoSeeder extends Seeder
{
    public const VISITORS = 60;

    public function run(VisitorStatusService $statuses): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoSeeder ne doit jamais être exécuté en production.');
        }

        if (Visitor::query()->exists()) {
            $this->command->warn('Des visiteurs existent déjà : DemoSeeder ignoré (lancez migrate:fresh --seed avant).');

            return;
        }

        if (! FamilyRotation::query()->exists()) {
            $this->call(FamilySeeder::class);
        }

        // Données reproductibles d'une exécution à l'autre.
        fake()->seed(2026);

        $familyIds = Family::query()->pluck('id')->all();
        $today = CarbonImmutable::today();
        $sundays = $this->sundays($today);

        DB::transaction(function () use ($statuses, $familyIds, $today, $sundays): void {
            for ($i = 0; $i < self::VISITORS - 2; $i++) {
                $count = fake()->randomElement([1, 1, 1, 1, 2, 2, 2, 3, 3, 3]);
                $dates = $this->visitDates($sundays, $count, $today);
                $visitor = $this->createVisitor($dates, $familyIds);

                // Environ 4 membres potentiels sur 10 ont été convertis.
                if (count($dates) === Visit::MAX_VISITS && fake()->boolean(40)) {
                    $convertedAt = $dates[2]->addWeeks(fake()->numberBetween(1, 3))->setTime(12, 30);

                    Member::query()->create([
                        'visitor_id' => $visitor->id,
                        'converted_by' => null,
                        'converted_at' => $convertedAt->greaterThan(now()) ? now() : $convertedAt,
                    ]);
                }

                $statuses->refresh($visitor);
            }

            // Deux nouveaux visiteurs aujourd'hui (statistique « new_today »).
            for ($i = 0; $i < 2; $i++) {
                $statuses->refresh($this->createVisitor([$today], $familyIds));
            }
        });

        $this->command->info(Visitor::query()->count().' visiteurs de démonstration créés.');
    }

    /**
     * @param  list<CarbonImmutable>  $dates
     * @param  list<int>  $familyIds
     */
    private function createVisitor(array $dates, array $familyIds): Visitor
    {
        $first = $dates[0]->setTime(fake()->numberBetween(9, 12), fake()->numberBetween(0, 59));
        $source = fake()->randomElement(Source::cases());
        $wantsGroup = fake()->boolean(45);

        $visitor = Visitor::factory()->create([
            'whatsapp' => null,
            'source' => $source,
            'source_other' => $source === Source::Autre ? 'Rencontre lors d\'une croisade' : null,
            'invited_by' => $source === Source::InviteMembre ? IvorianSamples::fullName() : null,
            'inviter_family_id' => $source === Source::InviteMembre && fake()->boolean(70) ? fake()->randomElement($familyIds) : null,
            'wants_whatsapp_group' => $wantsGroup,
            'consent_at' => $first,
        ]);

        $visitor->forceFill([
            // WhatsApp : même numéro, un autre numéro, ou aucun.
            'whatsapp' => $wantsGroup ? (fake()->boolean(70) ? $visitor->phone : IvorianSamples::mobileE164()) : null,
            'created_at' => $first,
            'updated_at' => $first,
        ])->saveQuietly();

        foreach ($dates as $index => $date) {
            Visit::factory()
                ->step($index + 1)
                ->for($visitor)
                ->onDate($date)
                ->create(['created_at' => $date->setTime(fake()->numberBetween(9, 12), fake()->numberBetween(0, 59))]);
        }

        return $visitor;
    }

    /**
     * Dates de visite : dimanches croissants, espacés de 1 à 4 semaines, jamais dans le futur.
     *
     * @param  list<CarbonImmutable>  $sundays
     * @return list<CarbonImmutable>
     */
    private function visitDates(array $sundays, int $count, CarbonImmutable $today): array
    {
        $first = fake()->randomElement(array_slice($sundays, 0, max(1, count($sundays) - ($count - 1) * 2)));
        $dates = [$first];

        for ($n = 2; $n <= $count; $n++) {
            $next = end($dates)->addWeeks(fake()->numberBetween(1, 4));

            if ($next->greaterThan($today)) {
                break;
            }

            $dates[] = $next;
        }

        return $dates;
    }

    /**
     * Dimanches des 12 derniers mois couverts par la rotation (première rotation : 2025-11).
     *
     * @return list<CarbonImmutable>
     */
    private function sundays(CarbonImmutable $today): array
    {
        $firstRotation = FamilyRotation::query()->orderBy('year')->orderBy('month')->first();
        $start = $today->subMonthsNoOverflow(12);

        if ($firstRotation !== null) {
            $rotationStart = CarbonImmutable::create($firstRotation->year, $firstRotation->month, 1);
            $start = $start->max($rotationStart);
        }

        $sundays = [];

        for ($day = $start->next(CarbonImmutable::SUNDAY); $day->lessThan($today); $day = $day->addWeek()) {
            $sundays[] = $day;
        }

        return $sundays;
    }
}
