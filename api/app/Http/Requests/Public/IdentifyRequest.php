<?php

namespace App\Http\Requests\Public;

use App\Enums\PhoneCountry;
use App\Rules\PhoneNumber;
use App\Services\PhoneNumberService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * POST /api/public/identify : { country, phone } (contrat §2).
 * Un numéro invalide pour le pays choisi donne une 422 sur `phone`.
 */
class IdentifyRequest extends FormRequest
{
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
        ];
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
