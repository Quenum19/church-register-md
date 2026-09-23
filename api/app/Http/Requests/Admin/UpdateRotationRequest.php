<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/admin/rotations/{year}/{month} : `{ family_id }`.
 * L'année et le mois de l'URL sont validés avec le corps (hors bornes => 422).
 */
class UpdateRotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Autorisation par la route (->can('rotations.manage')).
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return [
            ...$this->all(),
            'year' => self::routeInteger($this->route('year')),
            'month' => self::routeInteger($this->route('month')),
        ];
    }

    /**
     * Segment d'URL numérique (« 09 » accepté) converti en entier ; toute autre valeur est laissée
     * telle quelle pour que la validation la rejette.
     */
    private static function routeInteger(mixed $value): mixed
    {
        return is_string($value) && ctype_digit($value) ? (int) $value : $value;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'between:2000,2100'],
            'month' => ['required', 'integer', 'between:1,12'],
            'family_id' => ['required', 'integer', 'exists:families,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['year' => 'année', 'month' => 'mois', 'family_id' => 'famille'];
    }

    public function year(): int
    {
        return (int) $this->validated('year');
    }

    public function month(): int
    {
        return (int) $this->validated('month');
    }

    public function familyId(): int
    {
        return (int) $this->validated('family_id');
    }
}
