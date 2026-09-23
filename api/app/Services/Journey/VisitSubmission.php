<?php

namespace App\Services\Journey;

/**
 * Réponses validées et normalisées d'une étape du parcours.
 */
final readonly class VisitSubmission
{
    /**
     * @param  array<string, mixed>  $profile  champs du visiteur à créer (étape 1 uniquement, sinon vide)
     * @param  array<string, mixed>  $answers  contenu de `visits.answers` ({} pour la visite 1)
     */
    public function __construct(
        public int $step,
        public array $profile,
        public array $answers,
    ) {}
}
