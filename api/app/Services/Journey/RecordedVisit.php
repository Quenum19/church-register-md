<?php

namespace App\Services\Journey;

use App\Models\Family;
use App\Models\Visit;

/**
 * Résultat de POST /api/public/visits : visite créée (201) ou rejouée (200).
 * Seul toArray() est renvoyé au client : aucune donnée personnelle.
 */
final readonly class RecordedVisit
{
    public function __construct(
        public int $visitNumber,
        public ?Family $family,
        public bool $created,
    ) {}

    /**
     * Rejeu d'une visite existante (relation `family` chargée avec id et name).
     */
    public static function replayOf(Visit $visit): self
    {
        return new self($visit->visit_number, $visit->family, false);
    }

    public function status(): int
    {
        return $this->created ? 201 : 200;
    }

    /**
     * Corps du contrat : { visit_number, family: {id, name}|null, completed }.
     *
     * @return array{visit_number: int, family: array{id: int, name: string}|null, completed: bool}
     */
    public function toArray(): array
    {
        return [
            'visit_number' => $this->visitNumber,
            'family' => $this->family === null ? null : ['id' => $this->family->id, 'name' => $this->family->name],
            'completed' => $this->visitNumber >= Visit::MAX_VISITS,
        ];
    }
}
