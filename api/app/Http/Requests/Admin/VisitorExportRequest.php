<?php

namespace App\Http\Requests\Admin;

use App\Queries\VisitorListQuery;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/admin/exports/visitors.{csv,xlsx,pdf} : mêmes filtres que la liste, sans pagination.
 */
class VisitorExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Autorisation par la route (->can('visitors.export')).
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return VisitorListQuery::rules();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return VisitorListQuery::attributes();
    }

    public function listQuery(): VisitorListQuery
    {
        return VisitorListQuery::fromValidated($this->validated());
    }
}
