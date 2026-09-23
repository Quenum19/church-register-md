<?php

namespace App\Http\Requests\Admin\Users;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/admin/users/{user} — { name?, email?, role?, is_active? }.
 * Les règles « soi-même » et « dernier super_admin » (409) sont appliquées par UserManager.
 */
class UpdateUserRequest extends FormRequest
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
        $target = $this->route('user');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'email' => [
                'sometimes', 'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($target instanceof User ? $target->getKey() : null),
            ],
            'role' => ['sometimes', 'required', 'string', Rule::enum(Role::class)],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['is_active' => 'état du compte'];
    }
}
