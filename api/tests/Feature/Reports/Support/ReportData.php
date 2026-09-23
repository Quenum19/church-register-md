<?php

namespace Tests\Feature\Reports\Support;

use App\Models\Family;
use App\Models\Member;
use App\Models\Visit;
use App\Models\Visitor;
use App\Services\VisitorStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Mail;

/**
 * Jeux de données des tests de rapports (familles et rotations du FamilySeeder requises).
 *
 * Rotation de référence : 2025-12 Richesse, 2026-01 Sagesse, 2026-02 Force, 2026-03 Honneur,
 * 2026-06 Puissance, 2026-07 Richesse, 2026-08 Sagesse, 2026-09 Force, 2026-10 Honneur.
 */
final class ReportData
{
    /**
     * Instant donné dans le fuseau applicatif (à passer à travelTo()).
     */
    public static function at(string $datetime): CarbonImmutable
    {
        return CarbonImmutable::parse($datetime, 'Africa/Abidjan');
    }

    /**
     * Tâche planifiée (routes/console.php) dont la commande contient `$command`.
     */
    public static function scheduledEvent(string $command): Event
    {
        return collect(app(Schedule::class)->events())
            ->sole(fn (Event $event): bool => str_contains((string) $event->command, $command));
    }

    public static function family(string $name): Family
    {
        return Family::query()->where('name', $name)->firstOrFail();
    }

    /**
     * Visiteur et ses visites (numérotées 1, 2, 3 dans l'ordre donné).
     * Chaque visite : 'YYYY-MM-DD' (famille d'après la rotation du mois) ou
     * ['YYYY-MM-DD', Family|null] (famille imposée, null = aucune famille).
     *
     * @param  list<string|array{0: string, 1: Family|null}>  $visits
     * @param  array<string, mixed>  $attributes
     */
    public static function visitor(array $visits, array $attributes = []): Visitor
    {
        $visitor = Visitor::factory()->create($attributes);

        foreach ($visits as $index => $visit) {
            $factory = Visit::factory()->for($visitor)->step($index + 1);

            if (is_string($visit)) {
                $factory->onDate($visit)->create();

                continue;
            }

            [$date, $family] = $visit;
            $factory->onDate($date)->state(['family_id' => $family?->id])->create();
        }

        app(VisitorStatusService::class)->refresh($visitor);

        return $visitor;
    }

    /**
     * Conversion en membre à la date/heure donnée (fuseau applicatif).
     */
    public static function convert(Visitor $visitor, string $at): Member
    {
        $member = Member::query()->create([
            'visitor_id' => $visitor->id,
            'converted_by' => null,
            'converted_at' => CarbonImmutable::parse($at, 'Africa/Abidjan'),
        ]);

        app(VisitorStatusService::class)->refresh($visitor);

        return $member;
    }

    /**
     * Remplace le mailer par défaut par un transport qui lève une exception à chaque envoi.
     */
    public static function useFailingMailer(): FailingTransport
    {
        $transport = new FailingTransport;

        Mail::extend('failing', static fn (): FailingTransport => $transport);
        config(['mail.mailers.failing' => ['transport' => 'failing'], 'mail.default' => 'failing']);

        return $transport;
    }

    /**
     * Scénario multi-familles, multi-mois. Attendu pour août 2026 (famille Sagesse) :
     * v1 = 2 (awa, gisele), v2 = 1 (bakary), v3 = 1 (chantal), total = 4, conversions = 1 (moussa).
     *
     * @return array<string, Visitor>
     */
    public static function scenario(): array
    {
        $sagesse = self::family('Sagesse');
        $force = self::family('Force');

        $v = [];

        // Comptés en août 2026 (Sagesse).
        $v['awa'] = self::visitor(['2026-08-02'], ['full_name' => 'Awa Koné', 'phone' => '+2250700000001']);
        $v['bakary'] = self::visitor(['2026-07-05', '2026-08-09'], ['full_name' => 'Bakary Traoré', 'phone' => '+2250700000002']);
        $v['chantal'] = self::visitor(['2026-06-07', '2026-07-12', '2026-08-16'], ['full_name' => 'Chantal Yao', 'phone' => '+2250700000003']);
        $v['gisele'] = self::visitor(['2026-08-31'], ['full_name' => 'Gisèle N\'Guessan', 'phone' => '+2250700000007']);

        // Exclus d'août 2026 : autre famille, sans famille, autre mois, autre année.
        $v['didier'] = self::visitor([['2026-08-23', $force]], ['full_name' => 'Didier Kouadio', 'phone' => '+2250700000004']);
        $v['fatou'] = self::visitor([['2026-08-30', null]], ['full_name' => 'Fatou Diallo', 'phone' => '+2250700000006']);
        $v['emile'] = self::visitor(['2026-09-06'], ['full_name' => 'Émile Kassi', 'phone' => '+2250700000005']);
        $v['hortense'] = self::visitor(['2026-09-01'], ['full_name' => 'Hortense Bamba', 'phone' => '+2250700000008']);
        $v['ancien'] = self::visitor([['2025-08-03', $sagesse]], ['full_name' => 'Jean Ancien', 'phone' => '+2250700000009']);

        // Conversions.
        // 1re visite accueillie par Sagesse (janvier 2026), convertie en août 2026 : comptée en août.
        $v['moussa'] = self::visitor(['2026-01-04', '2026-02-01', '2026-03-01'], ['full_name' => 'Moussa Cissé', 'phone' => '+2250700000010']);
        self::convert($v['moussa'], '2026-08-20 11:00');

        // 1re visite accueillie par Richesse, convertie en août : pas dans le rapport de Sagesse.
        $v['rokia'] = self::visitor(['2025-12-07', '2026-01-11', '2026-02-08'], ['full_name' => 'Rokia Sangaré', 'phone' => '+2250700000011']);
        self::convert($v['rokia'], '2026-08-25 09:00');

        // 1re visite Sagesse mais convertis hors du mois (bornes exclues).
        $v['avant'] = self::visitor(['2026-01-18', '2026-02-15', '2026-03-15'], ['full_name' => 'Paul Avant', 'phone' => '+2250700000012']);
        self::convert($v['avant'], '2026-07-31 23:59:59');
        $v['apres'] = self::visitor(['2026-01-25', '2026-02-22', '2026-03-22'], ['full_name' => 'Marie Après', 'phone' => '+2250700000013']);
        self::convert($v['apres'], '2026-09-01 00:00:00');

        return $v;
    }
}
