<?php

namespace App\Http\Requests\Reports;

use App\Models\ReportRecipient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * Règles communes à la création et à la modification d'un destinataire des rapports.
 *
 * Unicité (family_id, email) vérifiée EN APPLICATION, y compris pour les destinataires globaux :
 * l'index unique de MariaDB ne bloque pas deux lignes dont family_id est NULL.
 * Les adresses sont enregistrées en minuscules, sans espaces autour.
 */
abstract class RecipientRequest extends FormRequest
{
    public const DUPLICATE_MESSAGE = 'Cette adresse reçoit déjà ces rapports.';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Destinataire modifié (null à la création).
     */
    abstract protected function current(): ?ReportRecipient;

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => Str::lower(trim($email))]);
        }
    }

    /**
     * @return list<string>
     */
    protected function emailRules(): array
    {
        return ['string', 'max:190', 'email:rfc,strict'];
    }

    /**
     * @return list<mixed>
     */
    protected function familyRules(): array
    {
        return ['nullable', 'integer', 'exists:families,id'];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['email', 'family_id'])) {
                    return;
                }

                if ($this->isDuplicate()) {
                    $validator->errors()->add('email', self::DUPLICATE_MESSAGE);
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'family_id' => 'famille',
            'name' => 'nom',
            'email' => 'adresse e-mail',
            'active' => 'actif',
        ];
    }

    /**
     * Même adresse déjà enregistrée pour la même famille (ou parmi les globaux), hors ce destinataire.
     */
    private function isDuplicate(): bool
    {
        $current = $this->current();

        $email = $this->has('email') ? $this->input('email') : $current?->email;
        $familyId = $this->has('family_id') ? $this->input('family_id') : $current?->family_id;

        if (! is_string($email)) {
            return false;
        }

        return ReportRecipient::query()
            ->where('email', Str::lower($email))
            ->when(
                $familyId === null,
                fn ($query) => $query->whereNull('family_id'),
                fn ($query) => $query->where('family_id', (int) $familyId),
            )
            ->when($current !== null, fn ($query) => $query->whereKeyNot($current?->id))
            ->exists();
    }
}
