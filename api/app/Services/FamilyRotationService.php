<?php

namespace App\Services;

use App\Models\Family;
use App\Models\FamilyRotation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Rotation mensuelle des familles d'accueil, lue dans `family_rotations`.
 *
 * Les dates sont interprétées dans le fuseau applicatif (APP_TIMEZONE = Africa/Abidjan).
 * Un mois sans rotation renvoie null : l'appelant enregistre alors la visite avec
 * `family_id` NULL et journalise une alerte (cahier, « Rotations »).
 */
class FamilyRotationService
{
    /** Ancre historique : novembre 2025 = 1re famille dans l'ordre de rotation (Puissance). */
    public const ANCHOR_YEAR = 2025;

    public const ANCHOR_MONTH = 11;

    /**
     * Famille de service pour le mois contenant `$date`.
     */
    public function familyFor(CarbonInterface $date): ?Family
    {
        $local = CarbonImmutable::instance($date)->setTimezone($this->timezone());

        return $this->familyForMonth($local->year, $local->month);
    }

    public function familyForMonth(int $year, int $month): ?Family
    {
        return Family::query()
            ->whereHas('rotations', function ($query) use ($year, $month): void {
                $query->where('year', $year)->where('month', $month);
            })
            ->first();
    }

    /**
     * Famille du mois en cours.
     */
    public function current(): ?Family
    {
        return $this->familyFor($this->now());
    }

    /**
     * Famille du mois suivant.
     */
    public function next(): ?Family
    {
        return $this->familyFor($this->now()->startOfMonth()->addMonthNoOverflow());
    }

    /**
     * Garantit une rotation pour chaque mois, du mois courant jusqu'au mois courant + `$months`.
     * Les mois manquants sont complétés en poursuivant l'ordre (position) des familles actives
     * à partir de la dernière rotation connue ; les rotations existantes ne sont jamais modifiées.
     *
     * @return int nombre de rotations créées
     */
    public function ensureMonthsAhead(int $months = 24): int
    {
        /** @var Collection<int, Family> $families */
        $families = Family::query()->active()->ordered()->get()->values();

        if ($families->isEmpty() || $months < 0) {
            return 0;
        }

        $start = $this->now()->startOfMonth();
        $startIndex = $this->monthIndex($start->year, $start->month);
        $endIndex = $startIndex + $months;

        $existing = FamilyRotation::query()
            ->with('family')
            ->whereRaw('(year * 12 + month - 1) between ? and ?', [$startIndex, $endIndex])
            ->get()
            ->keyBy(fn (FamilyRotation $rotation): int => $this->monthIndex($rotation->year, $rotation->month));

        // Point de départ : dernière rotation antérieure au mois courant (si elle existe).
        $previous = FamilyRotation::query()
            ->with('family')
            ->whereRaw('(year * 12 + month - 1) < ?', [$startIndex])
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->first();

        $previousFamily = $previous?->family;
        $previousIndex = $previous !== null ? $this->monthIndex($previous->year, $previous->month) : null;
        $created = 0;

        for ($index = $startIndex; $index <= $endIndex; $index++) {
            $rotation = $existing->get($index);

            if ($rotation === null) {
                $family = $previousFamily !== null && $previousIndex !== null
                    ? $this->advance($families, $previousFamily, $index - $previousIndex)
                    : $this->fromAnchor($families, $index);

                $rotation = FamilyRotation::query()->createOrFirst(
                    ['year' => intdiv($index, 12), 'month' => $index % 12 + 1],
                    ['family_id' => $family->id],
                );

                if ($rotation->wasRecentlyCreated) {
                    $created++;
                }

                $rotation->setRelation('family', $family);
            }

            $previousFamily = $rotation->family;
            $previousIndex = $index;
        }

        return $created;
    }

    /**
     * Famille située `$steps` rangs après `$from` dans l'ordre de rotation des familles actives.
     *
     * @param  Collection<int, Family>  $families
     */
    private function advance(Collection $families, Family $from, int $steps): Family
    {
        $count = $families->count();
        $position = $families->search(fn (Family $family): bool => $family->id === $from->id);

        if ($position === false) {
            // Famille désactivée : on repart de la première famille active qui la suit.
            $position = $families->search(fn (Family $family): bool => $family->position > $from->position);
            $position = ($position === false ? 0 : $position) - 1;
        }

        return $families[(($position + $steps) % $count + $count) % $count];
    }

    /**
     * Famille déduite de l'ancre historique (utilisée quand aucune rotation n'existe encore).
     *
     * @param  Collection<int, Family>  $families
     */
    private function fromAnchor(Collection $families, int $index): Family
    {
        $count = $families->count();
        $offset = $index - $this->monthIndex(self::ANCHOR_YEAR, self::ANCHOR_MONTH);

        return $families[($offset % $count + $count) % $count];
    }

    private function monthIndex(int $year, int $month): int
    {
        return $year * 12 + $month - 1;
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }

    private function timezone(): string
    {
        $timezone = config('app.timezone');

        return is_string($timezone) ? $timezone : 'Africa/Abidjan';
    }
}
