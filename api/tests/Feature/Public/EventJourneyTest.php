<?php

use App\Enums\VisitorStatus;
use App\Http\Requests\Public\IdentifyRequest;
use App\Models\Event;
use App\Models\Visit;
use App\Models\Visitor;
use App\Support\RateLimits;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Tests\Feature\Public\Support\PublicJourney;

/*
| Lien dédié d'un événement (contrat §2) : route publique d'en-tête, champ `event` de
| l'identification, report de l'événement sur la visite enregistrée, et parcours complet
| 1 -> 2 -> 3 où seule la 1re visite porte l'événement.
*/

uses(PublicJourney::class);

beforeEach(function (): void {
    $this->seedFamilies();
    $this->travelToAbidjan('2026-10-04');

    $this->event = Event::factory()->create([
        'name' => 'Culte spécial du 4 octobre',
        'slug' => 'culte-4-octobre',
        'event_date' => '2026-10-04',
    ]);
});

describe('GET /api/public/events/{slug}', function (): void {
    it('renvoie { slug, name, event_date } sans session ni cookie', function (): void {
        $response = $this->getJson('/api/public/events/culte-4-octobre')->assertOk();

        $this->assertStateless($response);

        expect($response->json())->toBe([
            'slug' => 'culte-4-octobre',
            'name' => 'Culte spécial du 4 octobre',
            'event_date' => '2026-10-04',
        ]);
    });

    it('renvoie une date nulle pour un événement sans date', function (): void {
        Event::factory()->withoutDate()->create(['name' => 'Évangélisation', 'slug' => 'evangelisation']);

        $this->getJson('/api/public/events/evangelisation')
            ->assertOk()
            ->assertJsonPath('event_date', null);
    });

    it('ne divulgue aucune donnée personnelle ni aucun compteur', function (): void {
        // Une personne inscrite par le lien : son nom ne doit jamais sortir de cette route.
        $token = $this->tokenFor();
        $this->postVisit($token, $this->visit1Answers(['full_name' => 'Aya Kouassi']))->assertCreated();
        Visit::query()->sole()->update(['event_id' => $this->event->id]);

        $response = $this->getJson('/api/public/events/culte-4-octobre')->assertOk();

        expect(array_keys((array) $response->json()))->toBe(['slug', 'name', 'event_date'])
            ->and((string) $response->getContent())
            ->not->toContain('Aya')
            ->not->toContain('visits_count')
            ->not->toContain('visitors_count')
            ->not->toContain('created_by')
            ->not->toContain('0700000000');
    });

    it('répond 404 not_found pour un slug inconnu, inactif ou hors format', function (string $slug): void {
        Event::factory()->inactive()->create(['slug' => 'culte-ferme']);

        $this->getJson('/api/public/events/'.$slug)
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found')
            ->assertJsonPath('message', 'Ressource introuvable.');
    })->with([
        'inconnu' => ['culte-inconnu'],
        'inactif' => ['culte-ferme'],
        'majuscules' => ['Culte-4-Octobre'],
        'accent' => ['culte-spécial'],
        'trop long' => ['culte-4-octobre-culte-4-octobre-culte-4-octobre-culte-4-octobre-culte'],
    ]);

    it('applique exactement les mêmes limites que les autres routes publiques', function (): void {
        $event = $this->getJson('/api/public/events/culte-4-octobre')->assertOk();
        $config = $this->getJson('/api/public/config')->assertOk();

        expect($event->headers->get('X-RateLimit-Limit'))
            ->toBe((string) RateLimits::PUBLIC_PER_MINUTE)
            ->toBe($config->headers->get('X-RateLimit-Limit'));

        // Aucune limite dédiée : le compteur « public » est partagé avec les autres routes.
        expect((int) $event->headers->get('X-RateLimit-Remaining'))
            ->toBeGreaterThan((int) $config->headers->get('X-RateLimit-Remaining'));
    });
});

describe('POST /api/public/identify avec `event`', function (): void {
    it('mémorise l\'identifiant de l\'événement dans le jeton, sans changer la réponse', function (): void {
        $withEvent = $this->postJson('/api/public/identify', [
            'country' => 'CI',
            'phone' => $this->phone,
            'event' => 'culte-4-octobre',
        ])->assertOk();

        $this->assertStateless($withEvent);

        expect(array_keys((array) $withEvent->json()))->toBe(['step', 'session_token', 'expires_in'])
            ->and($withEvent->json('step'))->toBe(1)
            ->and($withEvent->json('expires_in'))->toBe(900);

        // Le slug n'apparaît pas en clair : le jeton est chiffré.
        expect((string) $withEvent->getContent())->not->toContain('culte-4-octobre');

        $payload = json_decode(Crypt::decryptString((string) $withEvent->json('session_token')), true);

        expect($payload['event_id'])->toBe($this->event->id)
            ->and(array_keys($payload))->toBe(['phone_e164', 'country', 'step', 'jti', 'exp', 'event_id']);
    });

    it('n\'ajoute rien au jeton sans événement (jetons ordinaires inchangés)', function (mixed $event): void {
        $payload = ['country' => 'CI', 'phone' => $this->phone];

        if ($event !== 'absent') {
            $payload['event'] = $event;
        }

        $token = $this->postJson('/api/public/identify', $payload)->assertOk()->json('session_token');
        $decoded = json_decode(Crypt::decryptString((string) $token), true);

        expect(array_keys($decoded))->toBe(['phone_e164', 'country', 'step', 'jti', 'exp']);
    })->with([
        'champ absent' => ['absent'],
        'null' => [null],
        'chaîne vide' => [''],
    ]);

    it('refuse 422 sur `event` un slug inconnu ou inactif, sans émettre de jeton', function (string $slug): void {
        Event::factory()->inactive()->create(['slug' => 'culte-ferme']);

        $response = $this->postJson('/api/public/identify', [
            'country' => 'CI',
            'phone' => $this->phone,
            'event' => $slug,
        ])->assertUnprocessable();

        $this->assertStateless($response);

        $response->assertJsonValidationErrors(['event'])
            ->assertJsonPath('errors.event.0', IdentifyRequest::EVENT_MESSAGE);

        expect($response->json('session_token'))->toBeNull();
    })->with([
        'inconnu' => ['culte-inconnu'],
        'inactif' => ['culte-ferme'],
        'majuscules' => ['CULTE-4-OCTOBRE'],
        'trop long' => ['culte-4-octobre-culte-4-octobre-culte-4-octobre-culte-4-octobre-culte'],
    ]);

    it('n\'entame pas le quota du numéro quand le lien est refusé', function (): void {
        foreach (range(1, 6) as $ignored) {
            $this->postJson('/api/public/identify', [
                'country' => 'CI',
                'phone' => $this->phone,
                'event' => 'culte-inconnu',
            ])->assertUnprocessable();
        }

        // Le quota (5 jetons/heure) est intact : l'identification légitime aboutit.
        $this->identify()->assertOk()->assertJsonPath('step', 1);
    });
});

describe('POST /api/public/visits', function (): void {
    it('enregistre la visite avec l\'event_id du jeton, réponse inchangée', function (): void {
        $token = $this->postJson('/api/public/identify', [
            'country' => 'CI',
            'phone' => $this->phone,
            'event' => 'culte-4-octobre',
        ])->assertOk()->json('session_token');

        $response = $this->postVisit((string) $token, $this->visit1Answers())->assertCreated();

        expect(array_keys((array) $response->json()))->toBe(['visit_number', 'family', 'completed'])
            ->and($response->json('visit_number'))->toBe(1)
            ->and($response->json('completed'))->toBeFalse()
            ->and($response->json('family.name'))->toBe('Honneur');

        $visit = Visit::query()->sole();

        expect($visit->event_id)->toBe($this->event->id)
            ->and($visit->visit_number)->toBe(1)
            // Les règles de validation des réponses sont inchangées : le profil est bien stocké.
            ->and(Visitor::query()->sole()->full_name)->toBe('Aya Kouassi');
    });

    it('enregistre une visite sans événement par le lien ordinaire', function (): void {
        $this->registerFirstVisit();

        expect(Visit::query()->sole()->event_id)->toBeNull();
    });

    it('exige les mêmes réponses qu\'un parcours ordinaire (aucun allègement serveur)', function (): void {
        $token = $this->postJson('/api/public/identify', [
            'country' => 'CI',
            'phone' => $this->phone,
            'event' => 'culte-4-octobre',
        ])->assertOk()->json('session_token');

        $this->postVisit((string) $token, ['full_name' => 'Aya Kouassi'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['answers.commune', 'answers.quartier', 'answers.source']);

        expect(Visit::query()->count())->toBe(0);
    });

    it('enregistre la visite sans événement si l\'événement a été supprimé entre-temps', function (): void {
        $token = $this->postJson('/api/public/identify', [
            'country' => 'CI',
            'phone' => $this->phone,
            'event' => 'culte-4-octobre',
        ])->assertOk()->json('session_token');

        // Suppression possible tant qu'aucune visite n'est rattachée (409 event_has_visits sinon).
        $this->event->delete();

        $this->postVisit((string) $token, $this->visit1Answers())->assertCreated();

        expect(Visit::query()->sole()->event_id)->toBeNull();
    });

    it('mémorise un événement désactivé après l\'émission du jeton (personne déjà engagée)', function (): void {
        $token = $this->postJson('/api/public/identify', [
            'country' => 'CI',
            'phone' => $this->phone,
            'event' => 'culte-4-octobre',
        ])->assertOk()->json('session_token');

        $this->event->update(['active' => false]);

        $this->postVisit((string) $token, $this->visit1Answers())->assertCreated();

        expect(Visit::query()->sole()->event_id)->toBe($this->event->id);
    });

    it('rejoue la visite (idempotence) sans perdre l\'événement', function (): void {
        $token = (string) $this->postJson('/api/public/identify', [
            'country' => 'CI',
            'phone' => $this->phone,
            'event' => 'culte-4-octobre',
        ])->assertOk()->json('session_token');

        $key = (string) Str::uuid();

        $created = $this->postVisit($token, $this->visit1Answers(), true, $key)->assertCreated();
        $replayed = $this->postVisit($token, [], null, $key)->assertOk();

        expect($replayed->json())->toBe($created->json())
            ->and(Visit::query()->sole()->event_id)->toBe($this->event->id);
    });
});

describe('parcours complet 1 -> 2 -> 3', function (): void {
    it('ne porte l\'événement que sur la 1re visite', function (): void {
        // 4 octobre 2026 : culte spécial, inscription par le lien dédié.
        $token = (string) $this->postJson('/api/public/identify', [
            'country' => 'CI',
            'phone' => $this->phone,
            'event' => 'culte-4-octobre',
        ])->assertOk()->json('session_token');

        $this->postVisit($token, $this->visit1Answers())->assertCreated();

        // Dimanches suivants : cultes ordinaires, lien habituel du registre.
        $this->travelToAbidjan('2026-10-11');
        $this->postVisit($this->tokenFor(), $this->visit2Answers())->assertCreated();

        $this->travelToAbidjan('2026-10-18');
        $this->postVisit($this->tokenFor(), $this->visit3Answers())
            ->assertCreated()
            ->assertJsonPath('visit_number', 3)
            ->assertJsonPath('completed', true);

        $visitor = Visitor::query()->sole();
        $visits = $visitor->visits()->get();

        expect($visits->pluck('event_id', 'visit_number')->all())
            ->toBe([1 => $this->event->id, 2 => null, 3 => null])
            ->and($visitor->status)->toBe(VisitorStatus::MembrePotentiel);

        // L'origine reste lisible depuis la fiche admin même après le parcours complet.
        expect($visits->firstWhere('visit_number', 1)?->event_id)->toBe($this->event->id);
    });

    it('accepte une 2e visite venue d\'un autre événement', function (): void {
        $second = Event::factory()->create(['name' => 'Évangélisation', 'slug' => 'evangelisation']);

        $token = (string) $this->postJson('/api/public/identify', [
            'country' => 'CI',
            'phone' => $this->phone,
            'event' => 'culte-4-octobre',
        ])->assertOk()->json('session_token');
        $this->postVisit($token, $this->visit1Answers())->assertCreated();

        $this->travelToAbidjan('2026-10-11');
        $token = (string) $this->postJson('/api/public/identify', [
            'country' => 'CI',
            'phone' => $this->phone,
            'event' => 'evangelisation',
        ])->assertOk()->json('session_token');
        $this->postVisit($token, $this->visit2Answers())->assertCreated();

        expect(Visitor::query()->sole()->visits()->get()->pluck('event_id', 'visit_number')->all())
            ->toBe([1 => $this->event->id, 2 => $second->id]);
    });
});
