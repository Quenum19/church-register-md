<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * POST /api/auth/login — { email, password }.
 */
class LoginRequest extends FormRequest
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
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => PasswordRules::current(),
        ];
    }

    public function loginEmail(): string
    {
        return Str::lower(trim($this->string('email')->toString()));
    }

    public function loginPassword(): string
    {
        return $this->string('password')->toString();
    }
}
