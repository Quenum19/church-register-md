<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EventRequest;
use App\Http\Requests\Admin\StoreEventRequest;
use App\Http\Requests\Admin\UpdateEventRequest;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Événements (cultes spéciaux, évangélisations) — contrat d'API §4.
 *
 * Lecture : `visitors.view` (le lien et son QR code sont utiles à tout le dashboard).
 * Écriture : `events.manage`, accordée au seul super_admin.
 *
 * Un événement rattaché à des visites ne peut pas être supprimé (409 `event_has_visits`) :
 * on le désactive, ce qui ferme son lien public sans rien perdre du suivi.
 */
class EventController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * GET /api/admin/events (visitors.view) => `{ data: [Event] }`, date décroissante puis nom.
     */
    public function index(Request $request): JsonResponse
    {
        $events = Event::query()->withCounts()->ordered()->get();

        return new JsonResponse([
            'data' => $events->map(fn (Event $event): array => (new EventResource($event))->resolve($request))->values(),
        ]);
    }

    /**
     * POST /api/admin/events (events.manage) => 201 `{ data: Event }` ; slug déjà pris => 422.
     */
    public function store(StoreEventRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        /** @var User $user */
        $user = $request->user();

        $event = $this->persist(new Event, [...$data, 'created_by' => $user->id]);

        $this->audit->log('event.created', $event, [
            'slug' => $event->slug,
            'active' => $event->active,
        ]);

        return new JsonResponse(['data' => $this->resource($event, $request)], 201);
    }

    /**
     * PATCH /api/admin/events/{id} (events.manage) => `{ data: Event }`.
     * Journal `event.updated` avec les NOMS des champs modifiés (et le slug, qui est public).
     */
    public function update(UpdateEventRequest $request, Event $event): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $event->fill($data);
        $fields = array_keys($event->getDirty());

        if ($fields !== []) {
            $previousSlug = $event->getOriginal('slug');

            DB::transaction(function () use ($event, $fields, $previousSlug): void {
                $this->persist($event, []);

                $this->audit->log('event.updated', $event, [
                    'fields' => $fields,
                    'slug' => $event->slug,
                    'previous_slug' => is_string($previousSlug) ? $previousSlug : null,
                ]);
            });
        }

        return new JsonResponse(['data' => $this->resource($event, $request)]);
    }

    /**
     * DELETE /api/admin/events/{id} (events.manage) => 204 ;
     * 409 `event_has_visits` si des visites y sont rattachées.
     */
    public function destroy(Event $event): Response
    {
        if ($event->visits()->exists()) {
            throw new ApiException(
                'Des visites sont rattachées à cet événement : il ne peut pas être supprimé. '
                .'Désactivez-le plutôt, son lien cessera de fonctionner et le suivi sera conservé.',
                'event_has_visits',
                409,
            );
        }

        DB::transaction(function () use ($event): void {
            $this->audit->log('event.deleted', $event, ['slug' => $event->slug]);
            $event->delete();
        });

        return response()->noContent();
    }

    /**
     * Ressource relue avec ses agrégats (`visits_count`, `visitors_count`).
     *
     * @return array<string, mixed>
     */
    private function resource(Event $event, Request $request): array
    {
        $fresh = Event::query()->withCounts()->findOrFail($event->id);

        return (new EventResource($fresh))->resolve($request);
    }

    /**
     * Enregistre l'événement ; une violation de l'index unique (requêtes simultanées)
     * devient la même 422 que la validation applicative.
     *
     * @param  array<string, mixed>  $data
     */
    private function persist(Event $event, array $data): Event
    {
        try {
            $event->fill($data)->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['slug' => EventRequest::DUPLICATE_MESSAGE]);
        }

        return $event;
    }
}
