<?php

namespace App\Http\Requests\Admin\Users;

use App\Support\Pagination;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/admin/audit-logs — filtres `action`, `user_id`, pagination du contrat.
 */
class ListAuditLogsRequest extends FormRequest
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
            ...Pagination::rules(),
            'action' => ['sometimes', 'nullable', 'string', 'max:60'],
            'user_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function actionFilter(): ?string
    {
        $action = $this->validated('action');

        return is_string($action) && $action !== '' ? $action : null;
    }

    public function userIdFilter(): ?int
    {
        $id = $this->validated('user_id');

        return is_numeric($id) ? (int) $id : null;
    }
}
