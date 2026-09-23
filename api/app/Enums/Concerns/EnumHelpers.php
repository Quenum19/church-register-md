<?php

namespace App\Enums\Concerns;

/**
 * Méthodes utilitaires communes aux enums « string-backed » du domaine.
 */
trait EnumHelpers
{
    /**
     * Valeurs brutes de l'enum, dans l'ordre de déclaration.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Couples valeur => libellé français (listes déroulantes, exports).
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
