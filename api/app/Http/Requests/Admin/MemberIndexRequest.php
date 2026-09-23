<?php

namespace App\Http\Requests\Admin;

use App\Queries\VisitorListQuery;
use App\Support\Pagination;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/admin/members : recherche (même sémantique que la liste des visiteurs) + pagination.
 */
class MemberIndexRequest extends FormRequest
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
            ...Pagination::rules(),
            'search' => VisitorListQuery::rules()['search'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['search' => 'recherche', 'page' => 'page', 'per_page' => 'nombre par page'];
    }

    public function search(): ?string
    {
        $search = $this->validated('search');

        return is_string($search) && trim($search) !== '' ? trim($search) : null;
    }
}
