<?php

namespace App\Services\Journey;

use App\Enums\PhoneCountry;
use App\Enums\ReturnReason;
use App\Enums\Source;
use App\Enums\VisitReason;
use App\Services\PhoneNumberService;
use Closure;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * Validation et normalisation des `answers` de POST /api/public/visits selon l'étape du jeton
 * (contrat §2). Les clés d'erreur suivent la requête : `answers.full_name`,
 * `answers.whatsapp.number`, `consent`…
 *
 * - Chaînes rognées, chaînes vides converties en null (en plus des middlewares globaux).
 * - Seuls les champs de l'étape sont lus : aux étapes 2 et 3, tout champ de profil (nom…)
 *   est ignoré ; un champ conditionnel non applicable (`source_other` sans « autre »…) est ignoré.
 * - Les valeurs sont stockées telles quelles (HTML compris) : l'échappement se fait à l'affichage.
 */
class VisitAnswersValidator
{
    public const MAX_RETURN_REASONS = 5;

    /**
     * Bornes de TAILLE des `answers`, appliquées AVANT toute règle de validation : sans elles,
     * `answers.return_reasons.*` serait développée pour chaque élément d'un tableau non borné
     * (coût CPU linéaire et un message d'erreur par élément), sans aucune authentification.
     * Au-delà : une seule erreur 422, à coût constant.
     */
    public const MAX_KEYS = 40;

    /** Nombre maximal d'éléments d'un tableau de `answers` (dont `return_reasons`). */
    public const MAX_ARRAY_ITEMS = 20;

    /** Profondeur maximale (`answers` = 1, `answers.whatsapp` = 2). */
    public const MAX_DEPTH = 3;

    private const TOO_LARGE = 'Les réponses au formulaire sont invalides.';

    private const INVALID_CHOICE = 'Choix invalide.';

    private const INVALID_WHATSAPP = 'Le numéro WhatsApp est invalide.';

    private const CONSENT_REQUIRED = 'Votre accord est nécessaire pour enregistrer votre visite.';

    private const OTHER_TOO_LONG = 'La précision ne doit pas dépasser 200 caractères.';

    /** @var array<string, string> */
    private const MESSAGES = [
        'consent.required' => self::CONSENT_REQUIRED,
        'consent.accepted' => self::CONSENT_REQUIRED,

        'answers.full_name.required' => 'Indiquez votre nom et vos prénoms.',
        'answers.full_name.string' => 'Le nom est invalide.',
        'answers.full_name.max' => 'Le nom ne doit pas dépasser :max caractères.',
        'answers.commune.required' => 'Indiquez votre commune.',
        'answers.commune.string' => 'La commune est invalide.',
        'answers.commune.max' => 'La commune ne doit pas dépasser :max caractères.',
        'answers.quartier.required' => 'Indiquez votre quartier.',
        'answers.quartier.string' => 'Le quartier est invalide.',
        'answers.quartier.max' => 'Le quartier ne doit pas dépasser :max caractères.',
        'answers.source.required' => "Indiquez comment vous avez connu l'Église.",
        'answers.source.string' => "Indiquez comment vous avez connu l'Église.",
        'answers.source.enum' => "Indiquez comment vous avez connu l'Église.",
        'answers.source_other.required' => "Précisez comment vous avez connu l'Église.",
        'answers.source_other.string' => "Précisez comment vous avez connu l'Église.",
        'answers.source_other.max' => self::OTHER_TOO_LONG,
        'answers.invited_by.required' => 'Indiquez le nom de la personne qui vous a invité(e).',
        'answers.invited_by.string' => 'Le nom de la personne qui vous a invité(e) est invalide.',
        'answers.invited_by.max' => 'Le nom de la personne qui vous a invité(e) ne doit pas dépasser :max caractères.',
        'answers.inviter_family_id.integer' => 'La famille sélectionnée est invalide.',
        'answers.inviter_family_id.exists' => 'La famille sélectionnée est invalide.',
        'answers.inviter_family_id.prohibited' => "La famille de l'invitant ne s'indique que pour une invitation par un membre.",
        'answers.whatsapp.array' => self::INVALID_WHATSAPP,
        'answers.whatsapp.country.required_with' => 'Choisissez le pays du numéro WhatsApp.',
        'answers.whatsapp.country.string' => 'Le pays du numéro WhatsApp est invalide.',
        'answers.whatsapp.country.enum' => 'Le pays du numéro WhatsApp est invalide.',
        'answers.whatsapp.number.required' => 'Pour rejoindre le groupe WhatsApp, indiquez votre numéro WhatsApp ou cochez « même numéro ».',
        'answers.whatsapp.number.string' => self::INVALID_WHATSAPP,
        'answers.whatsapp.number.max' => self::INVALID_WHATSAPP,
        'answers.whatsapp_same_as_phone.boolean' => self::INVALID_CHOICE,
        'answers.wants_whatsapp_group.boolean' => self::INVALID_CHOICE,

        'answers.return_reasons.required' => 'Choisissez au moins une raison.',
        'answers.return_reasons.array' => 'Choisissez au moins une raison.',
        'answers.return_reasons.list' => self::INVALID_CHOICE,
        'answers.return_reasons.max' => 'Choisissez au plus :max raisons.',
        'answers.return_reasons.*.required' => self::INVALID_CHOICE,
        'answers.return_reasons.*.string' => self::INVALID_CHOICE,
        'answers.return_reasons.*.enum' => self::INVALID_CHOICE,
        'answers.return_reasons_other.required' => 'Précisez vos autres raisons.',
        'answers.return_reasons_other.string' => 'Précisez vos autres raisons.',
        'answers.return_reasons_other.max' => self::OTHER_TOO_LONG,

        'answers.visit_reason.required' => 'Choisissez la principale raison de vos visites.',
        'answers.visit_reason.string' => 'Choisissez la principale raison de vos visites.',
        'answers.visit_reason.enum' => 'Choisissez la principale raison de vos visites.',
        'answers.visit_reason_other.required' => 'Précisez la raison de vos visites.',
        'answers.visit_reason_other.string' => 'Précisez la raison de vos visites.',
        'answers.visit_reason_other.max' => self::OTHER_TOO_LONG,
    ];

    public function __construct(private readonly ValidationFactory $validation) {}

    /**
     * @throws ValidationException 422 avec des clés `answers.*` ou `consent`
     */
    public function validate(VisitToken $token, mixed $answers, mixed $consent): VisitSubmission
    {
        $answers = is_array($answers) ? $answers : [];

        // Bornes de taille d'abord : rien de coûteux (rognage, règles par élément) n'est
        // exécuté sur un tableau démesuré.
        $this->bound($answers, 'answers', 1, 0);

        $answers = $this->clean($answers, 'answers');

        return match ($token->step) {
            1 => $this->firstVisit($token, $answers, $consent),
            2 => $this->secondVisit($answers),
            default => $this->thirdVisit($answers),
        };
    }

    /**
     * Visite 1 : profil du visiteur (le consentement est obligatoire).
     *
     * @param  array<mixed>  $answers
     */
    private function firstVisit(VisitToken $token, array $answers, mixed $consent): VisitSubmission
    {
        $source = is_string($answers['source'] ?? null) ? Source::tryFrom($answers['source']) : null;
        $sameAsPhone = $this->isTrue($answers['whatsapp_same_as_phone'] ?? null);
        $wantsGroup = $this->isTrue($answers['wants_whatsapp_group'] ?? null);
        $whatsapp = is_array($answers['whatsapp'] ?? null) ? $answers['whatsapp'] : [];
        $whatsappCountry = is_string($whatsapp['country'] ?? null) ? PhoneCountry::tryFrom($whatsapp['country']) : null;

        $rules = [
            'consent' => ['bail', 'required', 'accepted'],
            'answers.full_name' => ['bail', 'required', 'string', 'max:100'],
            'answers.commune' => ['bail', 'required', 'string', 'max:80'],
            'answers.quartier' => ['bail', 'required', 'string', 'max:80'],
            'answers.source' => ['bail', 'required', 'string', Rule::enum(Source::class)],
            'answers.whatsapp_same_as_phone' => ['nullable', 'boolean'],
            'answers.wants_whatsapp_group' => ['nullable', 'boolean'],
        ];

        if ($source === Source::Autre) {
            $rules['answers.source_other'] = ['bail', 'required', 'string', 'max:200'];
        }

        if ($source === Source::InviteMembre) {
            $rules['answers.invited_by'] = ['bail', 'required', 'string', 'max:100'];
            $rules['answers.inviter_family_id'] = [
                'bail', 'nullable', 'integer', Rule::exists('families', 'id')->where('active', true),
            ];
        } else {
            // « Seulement si invite_membre » : null est accepté (le client l'envoie), une valeur est refusée.
            $rules['answers.inviter_family_id'] = ['prohibited'];
        }

        // « Même numéro » : le WhatsApp saisi est ignoré, le numéro identifié est repris.
        if (! $sameAsPhone) {
            $rules['answers.whatsapp'] = ['bail', 'nullable', 'array'];
            $rules['answers.whatsapp.country'] = [
                'bail', 'nullable', 'required_with:answers.whatsapp.number', 'string', Rule::enum(PhoneCountry::class),
            ];
            $rules['answers.whatsapp.number'] = [
                'bail', $wantsGroup ? 'required' : 'nullable', 'string', 'max:'.PhoneNumberService::MAX_RAW_LENGTH,
            ];

            if ($whatsappCountry !== null) {
                $rules['answers.whatsapp.number'][] = $this->whatsappNumber($whatsappCountry);
            }
        }

        $this->check(['answers' => $answers, 'consent' => $consent], $rules);

        // Validé ci-dessus : source connue, textes non vides.
        assert($source instanceof Source);
        $invited = $source === Source::InviteMembre;
        $number = $whatsapp['number'] ?? null;

        $whatsappE164 = match (true) {
            $sameAsPhone => $token->phoneE164,
            is_string($number) && $whatsappCountry !== null => PhoneNumberService::normalize($whatsappCountry->value, $number),
            default => null, // WhatsApp vide : NULL
        };

        $inviterFamily = $answers['inviter_family_id'] ?? null;

        return new VisitSubmission(1, [
            'full_name' => $answers['full_name'],
            'commune' => $answers['commune'],
            'quartier' => $answers['quartier'],
            'source' => $source,
            'source_other' => $source === Source::Autre ? $answers['source_other'] : null,
            'invited_by' => $invited ? $answers['invited_by'] : null,
            'inviter_family_id' => $invited && is_numeric($inviterFamily) ? (int) $inviterFamily : null,
            'whatsapp' => $whatsappE164,
            'wants_whatsapp_group' => $wantsGroup,
        ], []);
    }

    /**
     * Visite 2 : raisons du retour (1 à 5 valeurs de l'enum, dédupliquées).
     *
     * @param  array<mixed>  $answers
     */
    private function secondVisit(array $answers): VisitSubmission
    {
        $reasons = $answers['return_reasons'] ?? null;
        $withOther = is_array($reasons) && in_array(ReturnReason::Autres->value, $reasons, true);

        $rules = [
            'answers.return_reasons' => ['bail', 'required', 'array', 'list', 'max:'.self::MAX_RETURN_REASONS],
            'answers.return_reasons.*' => ['bail', 'required', 'string', Rule::enum(ReturnReason::class)],
        ];

        if ($withOther) {
            $rules['answers.return_reasons_other'] = ['bail', 'required', 'string', 'max:200'];
        }

        $this->check(['answers' => $answers], $rules, stopOnFirstFailure: true);

        assert(is_array($reasons));

        return new VisitSubmission(2, [], [
            'return_reasons' => array_values(array_unique($reasons)),
            'return_reasons_other' => $withOther ? $answers['return_reasons_other'] : null,
        ]);
    }

    /**
     * Visite 3 : motivation (précision obligatoire si « autres »).
     *
     * @param  array<mixed>  $answers
     */
    private function thirdVisit(array $answers): VisitSubmission
    {
        $reason = is_string($answers['visit_reason'] ?? null) ? VisitReason::tryFrom($answers['visit_reason']) : null;

        $rules = [
            'answers.visit_reason' => ['bail', 'required', 'string', Rule::enum(VisitReason::class)],
        ];

        if ($reason === VisitReason::Autres) {
            $rules['answers.visit_reason_other'] = ['bail', 'required', 'string', 'max:200'];
        }

        $this->check(['answers' => $answers], $rules, stopOnFirstFailure: true);

        assert($reason instanceof VisitReason);

        return new VisitSubmission(3, [], [
            'visit_reason' => $reason->value,
            'visit_reason_other' => $reason === VisitReason::Autres ? $answers['visit_reason_other'] : null,
        ]);
    }

    /**
     * Refuse un corps `answers` démesuré à coût constant : `count()` étant en O(1), un tableau
     * de 20 000 éléments est rejeté sans être parcouru et avec UN seul message d'erreur.
     *
     * @param  array<mixed>  $values
     * @param  int  $keys  nombre de clés déjà comptées
     * @return int nombre de clés comptées au total
     *
     * @throws ValidationException 422
     */
    private function bound(array $values, string $path, int $depth, int $keys): int
    {
        if ($depth > self::MAX_DEPTH || count($values) > self::MAX_ARRAY_ITEMS) {
            throw ValidationException::withMessages([$path => self::TOO_LARGE]);
        }

        foreach ($values as $key => $value) {
            if (++$keys > self::MAX_KEYS) {
                throw ValidationException::withMessages(['answers' => self::TOO_LARGE]);
            }

            if (is_array($value)) {
                $keys = $this->bound($value, $path.'.'.$key, $depth + 1, $keys);
            }
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, list<mixed>>  $rules
     * @param  bool  $stopOnFirstFailure  une seule erreur renvoyée : évite l'amplification des
     *                                    messages des règles par élément (`…return_reasons.*`)
     *
     * @throws ValidationException
     */
    private function check(array $data, array $rules, bool $stopOnFirstFailure = false): void
    {
        $validator = $this->validation->make($data, $rules, self::MESSAGES);

        if ($stopOnFirstFailure && $validator instanceof Validator) {
            $validator->stopOnFirstFailure();
        }

        $validator->validate();
    }

    /**
     * Numéro WhatsApp normalisable en E.164 pour le pays choisi (message propre au WhatsApp).
     */
    private function whatsappNumber(PhoneCountry $country): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($country): void {
            if (! is_string($value) || PhoneNumberService::normalize($country->value, $value) === null) {
                $fail(self::INVALID_WHATSAPP);
            }
        };
    }

    /**
     * Rogne les chaînes (espaces Unicode compris) et convertit les chaînes vides en null.
     * Une chaîne qui n'est pas de l'UTF-8 valide est refusée (422) plutôt que d'échouer en base.
     *
     * @param  array<mixed>  $values
     * @return array<mixed>
     *
     * @throws ValidationException
     */
    private function clean(array $values, string $path): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->clean($value, $path.'.'.$key);
            } elseif (is_string($value)) {
                if (! mb_check_encoding($value, 'UTF-8')) {
                    throw ValidationException::withMessages([$path.'.'.$key => 'Ce champ contient des caractères invalides.']);
                }

                $value = (string) preg_replace('~^[\s\x{FEFF}\x{200B}\x{200E}]+|[\s\x{FEFF}\x{200B}\x{200E}]+$~u', '', $value);
                $values[$key] = $value === '' ? null : $value;
            }
        }

        return $values;
    }

    /**
     * Case cochée : true, 1 ou « 1 » (les autres valeurs acceptées par la règle `boolean` valent false).
     */
    private function isTrue(mixed $value): bool
    {
        return in_array($value, [true, 1, '1'], true);
    }
}
