<?php

namespace App\Http\Requests\Admin;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Règles communes aux écritures d'événements (contrat d'API §4) :
 * `{ name ≤120, slug ≤60 unique, event_date (YYYY-MM-DD)|null, active }`.
 *
 * `StoreEventRequest` les rend obligatoires, `UpdateEventRequest` les rend toutes facultatives
 * (le responsable peut modifier le nom ET le slug d'un événement existant).
 */
abstract class EventRequest extends FormRequest
{
    /** Message d'un slug déjà pris (validation applicative et index unique). */
    public const DUPLICATE_MESSAGE = 'Ce lien est déjà utilisé par un autre événement. Choisissez-en un autre.';

    public const FORMAT_MESSAGE = 'Le lien ne peut contenir que des minuscules, des chiffres et des tirets (ex. « culte-special-octobre »).';

    public function authorize(): bool
    {
        // Autorisation par la route (->can('events.manage')).
        return true;
    }

    /**
     * Événement modifié (PATCH), ou null à la création : son propre slug ne doit pas
     * entrer en conflit avec lui-même.
     */
    protected function currentEvent(): ?Event
    {
        $event = $this->route('event');

        return $event instanceof Event ? $event : null;
    }

    /**
     * Règles des quatre champs, préfixées par `$presence` (`required` ou `sometimes`).
     *
     * @param  list<string>  $presence
     * @return array<string, list<mixed>>
     */
    protected function fieldRules(array $presence): array
    {
        $unique = Rule::unique('events', 'slug');
        $current = $this->currentEvent();

        if ($current !== null) {
            $unique->ignore($current->id);
        }

        return [
            'name' => [...$presence, 'string', 'max:'.Event::NAME_MAX_LENGTH],
            'slug' => [
                ...$presence,
                'string',
                'max:'.Event::SLUG_MAX_LENGTH,
                'regex:/'.Event::SLUG_PATTERN.'/',
                $unique,
            ],
            // `present` à la création (null accepté) : un événement peut n'avoir aucune date.
            'event_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nom',
            'slug' => 'lien',
            'event_date' => 'date',
            'active' => 'activation',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => self::FORMAT_MESSAGE,
            'slug.unique' => self::DUPLICATE_MESSAGE,
            'event_date.date_format' => 'La date doit être au format AAAA-MM-JJ.',
        ];
    }
}
