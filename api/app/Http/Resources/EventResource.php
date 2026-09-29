<?php

namespace App\Http\Resources;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `Event` du contrat d'API §4 :
 * `{ id, name, slug, event_date, active, url, visits_count, visitors_count, created_at }`.
 *
 * Les agrégats viennent de Event::scopeWithCounts() : construire la ressource à partir d'une
 * requête qui l'applique (sinon la prévention des attributs manquants lève une exception).
 * Référence courte `{ id, name, slug }` via EventResource::ref().
 *
 * @property-read Event $resource
 */
class EventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $event = $this->resource;

        return [
            'id' => $event->id,
            'name' => $event->name,
            'slug' => $event->slug,
            'event_date' => $event->event_date?->toDateString(),
            'active' => $event->active,
            'url' => $event->publicUrl(),
            'visits_count' => (int) $event->visits_count,
            'visitors_count' => (int) $event->visitors_count,
            'created_at' => VisitorSummaryResource::timestamp($event->created_at),
        ];
    }

    /**
     * Référence `{ id, name, slug }` ou null (fiche visiteur, `visits[].event`).
     *
     * @return array{id: int, name: string, slug: string}|null
     */
    public static function ref(?Event $event): ?array
    {
        return $event === null ? null : ['id' => $event->id, 'name' => $event->name, 'slug' => $event->slug];
    }
}
