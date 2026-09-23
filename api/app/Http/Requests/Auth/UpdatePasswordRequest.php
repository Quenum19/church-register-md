<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/auth/password — { current_password, password, password_confirmation }.
 * Le mot de passe actuel est vérifié ensuite (avec limitation des échecs), voir PasswordConfirmation.
 */
class UpdatePasswordRequest extends FormRequest
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
            'current_password' => PasswordRules::current(),
            'password' => PasswordRules::new(),
        ];
    }
}
