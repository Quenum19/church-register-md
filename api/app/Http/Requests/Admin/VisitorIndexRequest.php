<?php

namespace App\Http\Requests\Admin;

use App\Queries\VisitorListQuery;
use App\Support\Pagination;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/admin/visitors : filtres combinables (ET) + pagination. Paramètre invalide => 422.
 */
class VisitorIndexRequest extends FormRequest
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
        return [...Pagination::rules(), ...VisitorListQuery::rules()];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [...VisitorListQuery::attributes(), 'page' => 'page', 'per_page' => 'nombre par page'];
    }

    public function listQuery(): VisitorListQuery
    {
        return VisitorListQuery::fromValidated($this->validated());
    }
}
