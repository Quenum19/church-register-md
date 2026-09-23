<?php

namespace App\Http\Requests\Reports;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * POST /api/admin/report-recipients/test — { email? } : à défaut, l'adresse de l'utilisateur connecté.
 */
class SendTestEmailRequest extends FormRequest
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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['nullable', 'string', 'max:190', 'email:rfc,strict'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['email' => 'adresse e-mail'];
    }

    public function email(): ?string
    {
        $email = $this->validated('email');

        return is_string($email) && $email !== '' ? $email : null;
    }
}
