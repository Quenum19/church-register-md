<?php

namespace App\Http\Resources;

use App\Models\Family;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Famille d'accueil : `{ id, name, active, position }` (GET /api/admin/families).
 * Référence courte `{ id, name }` via FamilyResource::ref().
 *
 * @property-read Family $resource
 */
class FamilyResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, active: bool, position: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'active' => $this->resource->active,
            'position' => $this->resource->position,
        ];
    }

    /**
     * Référence `{ id, name }` ou null.
     *
     * @return array{id: int, name: string}|null
     */
    public static function ref(?Family $family): ?array
    {
        return $family === null ? null : ['id' => $family->id, 'name' => $family->name];
    }
}
