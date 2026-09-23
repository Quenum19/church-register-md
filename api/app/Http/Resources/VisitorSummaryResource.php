<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `VisitorSummary` du contrat d'API §4.
 *
 * `visit_count`, `first_visit_date` et `last_visit_date` proviennent des agrégats de
 * Visitor::scopeWithVisitStats() (liste) ou de la relation `visits` déjà chargée (fiche) :
 * jamais de requête par visiteur.
 *
 * @property-read Visitor $resource
 */
class VisitorSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $visitor = $this->resource;
        [$count, $first, $last] = self::visitStats($visitor);

        return [
            'id' => $visitor->id,
            'full_name' => $visitor->full_name,
            'phone' => $visitor->phone,
            'whatsapp' => $visitor->whatsapp,
            'commune' => $visitor->commune,
            'quartier' => $visitor->quartier,
            'status' => $visitor->status->value,
            'visit_count' => $count,
            'first_visit_date' => $first,
            'last_visit_date' => $last,
            'created_at' => self::timestamp($visitor->created_at),
        ];
    }

    /**
     * [nombre de visites, date de 1re visite, date de dernière visite].
     *
     * @return array{0: int, 1: string|null, 2: string|null}
     */
    public static function visitStats(Visitor $visitor): array
    {
        if ($visitor->relationLoaded('visits')) {
            $dates = $visitor->visits
                ->map(static fn (Visit $visit): string => $visit->visit_date->toDateString())
                ->sort()
                ->values();

            return [$dates->count(), $dates->first(), $dates->last()];
        }

        return [
            (int) $visitor->visits_count,
            self::date($visitor->visits_min_visit_date),
            self::date($visitor->visits_max_visit_date),
        ];
    }

    /**
     * Horodatage ISO 8601 avec fuseau (« 2026-09-22T08:00:00+00:00 »).
     */
    public static function timestamp(?CarbonInterface $value): ?string
    {
        return $value?->toIso8601String();
    }

    /**
     * Référence `{ id, name }` d'un compte (null si le compte a été supprimé).
     *
     * @return array{id: int, name: string}|null
     */
    public static function userRef(?User $user): ?array
    {
        return $user === null ? null : ['id' => $user->id, 'name' => $user->name];
    }

    /**
     * Date `YYYY-MM-DD` issue d'un agrégat SQL (chaîne brute, éventuellement « YYYY-MM-DD HH:MM:SS »).
     */
    private static function date(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }
}
