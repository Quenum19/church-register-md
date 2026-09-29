<?php

namespace App\Http\Requests\Public;

use App\Enums\PhoneCountry;
use App\Models\Event;
use App\Rules\PhoneNumber;
use App\Services\PhoneNumberService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * POST /api/public/identify : { country, phone, event? } (contrat §2).
 * Un numéro invalide pour le pays choisi donne une 422 sur `phone` ; un slug d'événement
 * inconnu ou désactivé donne une 422 sur `event`.
 */
class IdentifyRequest extends FormRequest
{
    /** Message unique d'un lien d'événement invalide, inconnu ou fermé. */
    public const EVENT_MESSAGE = "Ce lien d'événement n'est plus valide. Utilisez le lien habituel du registre.";

    /** Événement résolu (mémoïsé : la validation et le contrôleur le demandent tous les deux). */
    private ?Event $event = null;

    private bool $eventResolved = false;

    /**
     * Une seule erreur à la fois : un pays invalide n'entraîne pas l'analyse (coûteuse) du numéro.
     */
    protected $stopOnFirstFailure = true;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $phone = ['bail', 'required', 'string', 'max:'.PhoneNumberService::MAX_RAW_LENGTH];

        // Le numéro n'est vérifié que pour un pays connu (sinon seule l'erreur sur `country` s'affiche).
        if ($this->selectedCountry() !== null) {
            $phone[] = new PhoneNumber($this->selectedCountry()->value);
        }

        return [
            'country' => ['bail', 'required', 'string', Rule::enum(PhoneCountry::class)],
            'phone' => $phone,
            // Lien dédié d'un événement : facultatif. Le slug doit respecter la forme du contrat
            // (la comparaison SQL étant insensible à la casse, la regex évite qu'un « CULTE-X »
            // ouvre le lien « culte-x », que la route publique refuse) puis exister ET être actif.
            // Toute autre valeur => 422 sur `event` : un lien fermé ne se distingue pas d'un
            // lien inventé.
            'event' => [
                'bail',
                'sometimes',
                'nullable',
                'string',
                'max:'.Event::SLUG_MAX_LENGTH,
                'regex:/'.Event::SLUG_PATTERN.'/',
                Rule::exists('events', 'slug')->where('active', true),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'country.required' => 'Choisissez le pays de votre numéro.',
            'country.string' => 'Le pays sélectionné est invalide.',
            'country.enum' => 'Le pays sélectionné est invalide.',
            'phone.required' => 'Saisissez votre numéro de téléphone.',
            'phone.string' => 'Le numéro de téléphone est invalide.',
            'phone.max' => 'Le numéro de téléphone est invalide.',
            'event.string' => self::EVENT_MESSAGE,
            'event.max' => self::EVENT_MESSAGE,
            'event.regex' => self::EVENT_MESSAGE,
            'event.exists' => self::EVENT_MESSAGE,
        ];
    }

    /**
     * Un `event` vide (« ») vaut « aucun événement » : le SPA peut envoyer le champ sans valeur.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('event') === '') {
            $this->merge(['event' => null]);
        }
    }

    /**
     * Identifiant de l'événement d'origine, ou null (lien ordinaire). Mémorisé dans le jeton.
     */
    public function eventId(): ?int
    {
        return $this->event()?->id;
    }

    private function event(): ?Event
    {
        if ($this->eventResolved) {
            return $this->event;
        }

        $this->eventResolved = true;
        $slug = $this->validated('event');

        if (is_string($slug) && $slug !== '') {
            $this->event = Event::query()->active()->where('slug', $slug)->first(['id']);
        }

        return $this->event;
    }

    public function country(): PhoneCountry
    {
        return $this->selectedCountry() ?? throw new LogicException('Requête non validée.');
    }

    /**
     * Numéro normalisé en E.164 (garanti non nul après validation).
     */
    public function phoneE164(): string
    {
        $phone = $this->validated('phone');

        return (is_string($phone) ? PhoneNumberService::normalize($this->country()->value, $phone) : null)
            ?? throw new LogicException('Requête non validée.');
    }

    private function selectedCountry(): ?PhoneCountry
    {
        $country = $this->input('country');

        return is_string($country) ? PhoneCountry::tryFrom($country) : null;
    }
}
