<?php

namespace App\Rules;

use App\Enums\PhoneCountry;
use App\Services\PhoneNumberService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Règle de validation : numéro normalisable en E.164 pour le pays donné (contrat §1).
 *
 *   'phone' => ['required', 'string', new PhoneNumber($this->input('country'))]
 *
 * La valeur normalisée s'obtient ensuite avec PhoneNumberService::normalize().
 */
class PhoneNumber implements ValidationRule
{
    public function __construct(private readonly mixed $country = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $country = is_string($this->country) && $this->country !== '' ? $this->country : PhoneCountry::DEFAULT->value;

        if (! is_string($value) || PhoneNumberService::normalize($country, $value) === null) {
            $fail('Le numéro de téléphone est invalide.');
        }
    }
}
