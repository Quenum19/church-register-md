<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * POST /api/public/visits : validation de FORME uniquement (contrat §2).
 *
 * Les `answers` dépendent de l'étape contenue dans le jeton : elles sont validées après
 * le rejeu idempotent et la vérification du jeton (App\Services\Journey\VisitAnswersValidator).
 */
class StoreVisitRequest extends FormRequest
{
    /** Longueur maximale d'un jeton de parcours (un jeton réel fait environ 300 caractères). */
    public const MAX_TOKEN_LENGTH = 2048;

    /**
     * Une seule erreur de forme est renvoyée : rien ne sert de détailler quatre champs
     * techniques à un appelant non authentifié (et aucune amplification des messages).
     */
    protected $stopOnFirstFailure = true;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'session_token' => ['bail', 'required', 'string', 'max:'.self::MAX_TOKEN_LENGTH],
            'idempotency_key' => ['bail', 'required', 'string', 'uuid'],
            'answers' => ['present', 'array'],
            'consent' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $session = 'Votre session de saisie est invalide. Merci de saisir à nouveau votre numéro.';
        $key = "L'identifiant d'envoi est invalide. Merci de réessayer.";

        return [
            'session_token.required' => $session,
            'session_token.string' => $session,
            'session_token.max' => $session,
            'idempotency_key.required' => $key,
            'idempotency_key.string' => $key,
            'idempotency_key.uuid' => $key,
            'answers.present' => 'Les réponses au formulaire sont manquantes.',
            'answers.array' => 'Les réponses au formulaire sont invalides.',
            'consent.boolean' => 'La valeur du consentement est invalide.',
        ];
    }

    public function sessionToken(): string
    {
        return (string) $this->validated('session_token');
    }

    /**
     * Clé d'idempotence en minuscules (forme canonique d'un UUID).
     */
    public function idempotencyKey(): string
    {
        return Str::lower((string) $this->validated('idempotency_key'));
    }

    public function answers(): mixed
    {
        return $this->validated('answers');
    }

    public function consent(): mixed
    {
        return $this->validated('consent');
    }
}
