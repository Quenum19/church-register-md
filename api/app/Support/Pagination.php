<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pagination des listes admin au format du contrat d'API §0 :
 * { "data": [...], "meta": { "current_page", "last_page", "per_page", "total" } }.
 *
 *   $request->validate([...Pagination::rules(), ...]);
 *   $page = Visitor::query()->paginate(Pagination::perPage($request));
 *   return Pagination::response($page, fn (Visitor $v) => new VisitorSummaryResource($v));
 */
final class Pagination
{
    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    /**
     * Règles de validation (hors bornes => 422).
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    public static function perPage(Request $request): int
    {
        $perPage = filter_var($request->query('per_page'), FILTER_VALIDATE_INT);

        return is_int($perPage) && $perPage >= 1 && $perPage <= self::MAX_PER_PAGE ? $perPage : self::DEFAULT_PER_PAGE;
    }

    /**
     * @param  LengthAwarePaginator<array-key, mixed>  $paginator
     * @return array{current_page: int, last_page: int, per_page: int, total: int}
     */
    public static function meta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => max(1, $paginator->lastPage()),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    /**
     * @param  LengthAwarePaginator<array-key, mixed>  $paginator
     * @param  (callable(mixed): mixed)|null  $map  transformation de chaque élément (ex. Resource)
     */
    public static function response(LengthAwarePaginator $paginator, ?callable $map = null): JsonResponse
    {
        $items = collect($paginator->items());

        return new JsonResponse([
            'data' => ($map !== null ? $items->map($map) : $items)->values(),
            'meta' => self::meta($paginator),
        ]);
    }
}
