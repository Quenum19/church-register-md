<?php

namespace App\Http\Requests\Reports;

use App\Services\Reports\MonthlyReportService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/admin/reports?year=YYYY — année parmi meta.available_years (défaut : année courante).
 * L'autorisation (visitors.view) est portée par la route.
 */
class ListReportsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(MonthlyReportService $reports): array
    {
        return [
            'year' => ['sometimes', 'required', 'integer', Rule::in($reports->availableYears())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'year.in' => "Aucun rapport n'est disponible pour cette année.",
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['year' => 'année'];
    }

    public function year(): int
    {
        return $this->has('year') ? $this->integer('year') : now()->year;
    }
}
