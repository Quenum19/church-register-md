<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /api/public/events/{slug} (contrat §2) : en-tête du lien dédié d'un événement.
 *
 * Réponse 200 `{ slug, name, event_date }` — AUCUNE donnée personnelle, aucun compteur,
 * aucun cookie (groupe public : sans session ni CSRF), mêmes limites que les autres routes
 * publiques. Slug inconnu ou événement désactivé => 404 `not_found` (réponse identique :
 * le lien d'un événement fermé ne se distingue pas d'un lien inventé).
 */
class EventController extends Controller
{
    public function __invoke(string $slug): JsonResponse
    {
        $event = Event::query()
            ->active()
            ->where('slug', $slug)
            ->first(['slug', 'name', 'event_date']);

        if ($event === null) {
            throw new NotFoundHttpException;
        }

        return new JsonResponse([
            'slug' => $event->slug,
            'name' => $event->name,
            'event_date' => $event->event_date?->toDateString(),
        ]);
    }
}
