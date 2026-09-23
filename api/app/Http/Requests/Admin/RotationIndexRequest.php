<?php

namespace App\Http\Requests\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/admin/rotations?from=YYYY-MM&months=1..36 (par défaut : mois courant, 12 mois).
 */
class RotationIndexRequest extends FormRequest
{
    public const DEFAULT_MONTHS = 12;

    public const MAX_MONTHS = 36;

    public function authorize(): bool
    {
        // Autorisation par la route (->can('visitors.view')).
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'nullable', 'string', 'regex:/^(19|20|21)\d{2}-(0[1-9]|1[0-2])$/'],
            'months' => ['sometimes', 'nullable', 'integer', 'between:1,'.self::MAX_MONTHS],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['from' => 'mois de début', 'months' => 'nombre de mois'];
    }

    /**
     * Premier mois de la plage : [année, mois].
     *
     * @return array{0: int, 1: int}
     */
    public function start(): array
    {
        $from = $this->validated('from');

        if (is_string($from) && $from !== '') {
            [$year, $month] = explode('-', $from);

            return [(int) $year, (int) $month];
        }

        $timezone = config('app.timezone');
        $now = CarbonImmutable::now(is_string($timezone) ? $timezone : 'Africa/Abidjan');

        return [$now->year, $now->month];
    }

    public function months(): int
    {
        $months = $this->validated('months');

        return is_numeric($months) ? (int) $months : self::DEFAULT_MONTHS;
    }
}
