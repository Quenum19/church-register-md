<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * { password } : confirmation du mot de passe avant une opération sensible
 * (POST /api/auth/two-factor/enable, DELETE /api/auth/two-factor).
 */
class ConfirmPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'password' => PasswordRules::current(),
        ];
    }
}
