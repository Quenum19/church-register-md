<?php

namespace App\Http\Resources;

use App\Models\Congregation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Congrégation : `{ id, name, active, position }`.
 * Référence courte `{ id, name }` via CongregationResource::ref().
 *
 * @property-read Congregation $resource
 */
class CongregationResource extends JsonResource
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
    public static function ref(?Congregation $congregation): ?array
    {
        return $congregation === null ? null : ['id' => $congregation->id, 'name' => $congregation->name];
    }
}
