<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use stdClass;

/**
 * Objet JSON exposé en tableau associatif PHP. Un tableau vide est stocké `{}` (et non `[]`).
 *
 * Côté API, sérialiser avec `(object) $model->answers` pour produire `{}` quand il est vide.
 *
 * @implements CastsAttributes<array<string, mixed>, array<string, mixed>|null>
 */
class JsonObject implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>|null  $value
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        $payload = is_array($value) && $value !== [] ? $value : new stdClass;

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
