<?php

namespace App\Http\Requests\Admin;

use App\Exports\EventFormExport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/admin/events/{event}/formulaire.pdf : `par_page` vaut 1 ou 2 (défaut 2).
 * Toute autre valeur est refusée (422), comme les filtres des exports de visiteurs.
 */
class EventFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Autorisation par la route (->can('visitors.view')).
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'par_page' => ['sometimes', 'nullable', Rule::in(EventFormExport::PER_PAGE)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['par_page' => 'nombre de fiches par page'];
    }

    public function perPage(): int
    {
        $value = $this->validated()['par_page'] ?? null;

        return is_numeric($value) ? (int) $value : EventFormExport::DEFAULT_PER_PAGE;
    }
}
