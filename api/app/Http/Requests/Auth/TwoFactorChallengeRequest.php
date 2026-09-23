<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/auth/two-factor/challenge — { code } (TOTP à 6 chiffres) ou { recovery_code }.
 */
class TwoFactorChallengeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $code = $this->input('code');

        if (is_string($code)) {
            $this->merge(['code' => preg_replace('/\s+/', '', $code)]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'required_without:recovery_code', 'string', 'regex:/^\d{6}$/'],
            'recovery_code' => ['nullable', 'required_without:code', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'Le code comporte 6 chiffres.',
            'code.required_without' => 'Saisissez le code à 6 chiffres ou un code de récupération.',
            'recovery_code.required_without' => 'Saisissez le code à 6 chiffres ou un code de récupération.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['code' => 'code', 'recovery_code' => 'code de récupération'];
    }

    public function totpCode(): ?string
    {
        $code = $this->validated('code');

        return is_string($code) && $code !== '' ? $code : null;
    }

    public function recoveryCode(): ?string
    {
        $code = $this->validated('recovery_code');

        return is_string($code) && $code !== '' ? $code : null;
    }
}
