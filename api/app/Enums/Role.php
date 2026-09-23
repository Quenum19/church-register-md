<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * Rôles des comptes du dashboard (contrat d'API §1).
 */
enum Role: string
{
    use EnumHelpers;

    case SuperAdmin = 'super_admin';
    case Moderateur = 'moderateur';
    case Lecteur = 'lecteur';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super administrateur',
            self::Moderateur => 'Modérateur',
            self::Lecteur => 'Lecteur',
        };
    }

    /**
     * Abilities accordées au rôle, dans l'ordre du contrat.
     *
     * @return list<string>
     */
    public function abilities(): array
    {
        $abilities = match ($this) {
            self::SuperAdmin => Ability::cases(),
            self::Moderateur => [Ability::VisitorsView, Ability::VisitorsUpdate, Ability::NotesCreate],
            self::Lecteur => [Ability::VisitorsView],
        };

        return array_map(static fn (Ability $ability): string => $ability->value, $abilities);
    }

    public function allows(Ability|string $ability): bool
    {
        $value = $ability instanceof Ability ? $ability->value : $ability;

        return in_array($value, $this->abilities(), true);
    }
}
