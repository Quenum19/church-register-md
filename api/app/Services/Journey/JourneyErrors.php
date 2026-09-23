<?php

namespace App\Services\Journey;

use App\Exceptions\ApiException;

/**
 * Erreurs métier du parcours visiteur (contrat §2). Aucun message ne contient de donnée personnelle.
 */
final class JourneyErrors
{
    public static function tokenInvalid(): ApiException
    {
        return new ApiException(
            'Votre session de saisie est invalide. Merci de saisir à nouveau votre numéro.',
            'token_invalid',
            401,
        );
    }

    public static function tokenExpired(): ApiException
    {
        return new ApiException(
            'Votre session de saisie a expiré. Merci de saisir à nouveau votre numéro.',
            'token_expired',
            410,
        );
    }

    public static function tokenUsed(): ApiException
    {
        return new ApiException(
            'Cette session de saisie a déjà servi. Merci de saisir à nouveau votre numéro.',
            'token_used',
            409,
        );
    }

    public static function stepMismatch(): ApiException
    {
        return new ApiException(
            'Votre parcours a changé entre-temps. Merci de saisir à nouveau votre numéro.',
            'step_mismatch',
            409,
        );
    }

    public static function alreadyToday(): ApiException
    {
        return new ApiException(
            "Votre visite est déjà enregistrée aujourd'hui. Merci et à bientôt !",
            'already_today',
            409,
        );
    }

    /**
     * Trop d'identifications pour ce numéro (5/heure). Message et code identiques à ceux de la
     * limite par IP : l'appelant ne peut pas savoir laquelle des deux s'est déclenchée.
     */
    public static function tooManyIdentifications(int $seconds): ApiException
    {
        return new ApiException(
            'Trop de requêtes. Veuillez réessayer dans quelques instants.',
            'too_many_requests',
            429,
            headers: ['Retry-After' => (string) max(1, $seconds)],
        );
    }

    public static function idempotencyConflict(): ApiException
    {
        return new ApiException(
            'Cet envoi a déjà été utilisé pour un autre enregistrement. Merci de réessayer.',
            'idempotency_conflict',
            409,
        );
    }
}
