<?php

namespace App\Http\Requests\Reports;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/admin/reports/{year}/{month}/send — { force?: bool } (renvoi d'un rapport déjà envoyé).
 * L'autorisation (reports.send) est portée par la route.
 */
class SendReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'force' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['force' => 'renvoi forcé'];
    }

    public function force(): bool
    {
        return $this->boolean('force');
    }
}
