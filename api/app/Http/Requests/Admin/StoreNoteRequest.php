<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/admin/visitors/{id}/notes : `{ body ≤ 2000 }`.
 */
class StoreNoteRequest extends FormRequest
{
    public const BODY_MAX_LENGTH = 2000;

    public function authorize(): bool
    {
        // Autorisation par la route (->can('notes.create')).
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.self::BODY_MAX_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['body' => 'note'];
    }

    public function body(): string
    {
        return trim((string) $this->validated('body'));
    }
}
