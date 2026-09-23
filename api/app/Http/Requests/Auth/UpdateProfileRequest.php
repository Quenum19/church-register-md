<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/auth/profile — { name?, email?, current_password? }.
 *
 * `current_password` est obligatoire quand l'e-mail change (identifiant de connexion et adresse
 * des liens de réinitialisation) : une session volée ne suffit pas à s'approprier le compte.
 * Le mot de passe est vérifié ensuite, avec limitation des échecs (PasswordConfirmation).
 */
class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => Str::lower(trim($email))]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user()?->getAuthIdentifier())],
            'current_password' => [Rule::requiredIf($this->changesEmail(...)), 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Saisissez votre mot de passe actuel pour changer d\'adresse e-mail.',
        ];
    }

    /**
     * Vrai si la requête modifie l'adresse e-mail du compte (après normalisation).
     */
    public function changesEmail(): bool
    {
        $email = $this->input('email');

        return is_string($email) && $email !== $this->user()?->getAttribute('email');
    }
}
