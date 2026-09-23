<?php

namespace App\Http\Requests\Admin;

use App\Enums\PhoneCountry;
use App\Models\Visitor;
use App\Rules\PhoneNumber;
use App\Services\PhoneNumberService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PATCH /api/admin/visitors/{id} : liste blanche du contrat d'API §4. Tout autre champ
 * (téléphone, statut, source…) est ignoré ; le statut n'est jamais modifiable ici.
 *
 * `whatsapp` : `{ country, number }` normalisé en E.164, ou `null` / numéro vide => NULL.
 */
class UpdateVisitorRequest extends FormRequest
{
    /** Champs modifiables, dans l'ordre du contrat. */
    public const FIELDS = ['full_name', 'whatsapp', 'commune', 'quartier', 'invited_by', 'wants_whatsapp_group'];

    public function authorize(): bool
    {
        // Autorisation par la route (->can('visitors.update')).
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'required', 'string', 'max:100'],
            'commune' => ['sometimes', 'required', 'string', 'max:80'],
            'quartier' => ['sometimes', 'required', 'string', 'max:80'],
            'invited_by' => ['sometimes', 'nullable', 'string', 'max:100'],
            'wants_whatsapp_group' => ['sometimes', 'required', 'boolean'],
            'whatsapp' => ['sometimes', 'nullable', 'array:country,number'],
            'whatsapp.country' => ['required_with:whatsapp.number', 'string', Rule::enum(PhoneCountry::class)],
            'whatsapp.number' => [
                'nullable',
                'string',
                'max:'.PhoneNumberService::MAX_RAW_LENGTH,
                new PhoneNumber($this->input('whatsapp.country')),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'full_name' => 'nom complet',
            'commune' => 'commune',
            'quartier' => 'quartier',
            'invited_by' => 'invité(e) par',
            'wants_whatsapp_group' => 'groupe WhatsApp',
            'whatsapp' => 'WhatsApp',
            'whatsapp.country' => 'pays du numéro WhatsApp',
            'whatsapp.number' => 'numéro WhatsApp',
        ];
    }

    /**
     * Cohérence de l'état final : rejoindre le groupe WhatsApp exige un numéro WhatsApp.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $visitor = $this->route('visitor');

            if (! $visitor instanceof Visitor || $validator->errors()->isNotEmpty()) {
                return;
            }

            $wantsGroup = $this->has('wants_whatsapp_group')
                ? $this->boolean('wants_whatsapp_group')
                : $visitor->wants_whatsapp_group;
            $whatsapp = $this->has('whatsapp') ? $this->normalizedWhatsapp() : $visitor->whatsapp;

            if ($wantsGroup && $whatsapp === null) {
                $validator->errors()->add('whatsapp', 'Un numéro WhatsApp est nécessaire pour rejoindre le groupe WhatsApp.');
            }
        }];
    }

    /**
     * Attributs à appliquer au visiteur (uniquement les champs envoyés).
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        $validated = $this->validated();
        $changes = [];

        foreach (['full_name', 'commune', 'quartier'] as $field) {
            if (array_key_exists($field, $validated)) {
                $changes[$field] = trim((string) $validated[$field]);
            }
        }

        if (array_key_exists('invited_by', $validated)) {
            $invitedBy = is_string($validated['invited_by']) ? trim($validated['invited_by']) : '';
            $changes['invited_by'] = $invitedBy === '' ? null : $invitedBy;
        }

        if (array_key_exists('wants_whatsapp_group', $validated)) {
            $changes['wants_whatsapp_group'] = $this->boolean('wants_whatsapp_group');
        }

        if (array_key_exists('whatsapp', $validated)) {
            $changes['whatsapp'] = $this->normalizedWhatsapp();
        }

        return $changes;
    }

    /**
     * Numéro WhatsApp E.164, ou null si absent / vide.
     */
    private function normalizedWhatsapp(): ?string
    {
        $whatsapp = $this->input('whatsapp');

        if (! is_array($whatsapp)) {
            return null;
        }

        $number = $whatsapp['number'] ?? null;
        $country = $whatsapp['country'] ?? PhoneCountry::DEFAULT->value;

        if (! is_string($number) || trim($number) === '' || ! is_string($country)) {
            return null;
        }

        return PhoneNumberService::normalize($country, $number);
    }
}
