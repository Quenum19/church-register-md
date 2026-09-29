<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\User;
use App\Models\Visit;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\Support\AdminFixtures;

/*
| Événements (cultes spéciaux, évangélisations) — contrat d'API §4 :
| liste, création, modification (nom ET lien), suppression protégée, journal d'audit
| et matrice des permissions (lecture ouverte à visitors.view, écriture super_admin seul).
*/

beforeEach(function (): void {
    $this->travelTo(AdminFixtures::now());
    $this->superAdmin = User::factory()->superAdmin()->create();
});

describe('liste', function (): void {
    it('renvoie des Event exactement au format du contrat', function (): void {
        app(SettingsService::class)->set('public_url', 'https://registre.newinechurch.org/');

        $event = Event::factory()->create([
            'name' => 'Culte spécial du 4 octobre',
            'slug' => 'culte-4-octobre',
            'event_date' => '2026-10-04',
            'created_by' => $this->superAdmin->id,
        ]);

        // Deux visites d'une même personne + une autre personne : 3 visites, 2 visiteurs.
        $awa = AdminFixtures::visitor(['2026-09-06', '2026-09-13'], ['full_name' => 'Awa Koné']);
        $awa->visits()->update(['event_id' => $event->id]);
        $yao = AdminFixtures::visitor(['2026-09-06'], ['full_name' => 'Yao Traoré']);
        $yao->visits()->update(['event_id' => $event->id]);

        $this->actingAs($this->superAdmin)
            ->getJson('/api/admin/events')
            ->assertOk()
            ->assertExactJson(['data' => [[
                'id' => $event->id,
                'name' => 'Culte spécial du 4 octobre',
                'slug' => 'culte-4-octobre',
                'event_date' => '2026-10-04',
                'active' => true,
                'url' => 'https://registre.newinechurch.org/e/culte-4-octobre',
                'visits_count' => 3,
                'visitors_count' => 2,
                'created_at' => $event->created_at?->toIso8601String(),
            ]]]);
    });

    it('trie par date décroissante puis par nom, les événements sans date en dernier', function (): void {
        Event::factory()->create(['name' => 'Évangélisation', 'slug' => 'evangelisation', 'event_date' => '2026-10-04']);
        Event::factory()->create(['name' => 'Culte spécial', 'slug' => 'culte-special', 'event_date' => '2026-10-04']);
        Event::factory()->create(['name' => 'Veillée', 'slug' => 'veillee', 'event_date' => '2026-11-01']);
        Event::factory()->withoutDate()->create(['name' => 'Sans date', 'slug' => 'sans-date']);

        $slugs = collect($this->actingAs($this->superAdmin)->getJson('/api/admin/events')->assertOk()->json('data'))
            ->pluck('slug')
            ->all();

        expect($slugs)->toBe(['veillee', 'culte-special', 'evangelisation', 'sans-date']);
    });

    it('charge les compteurs en un nombre constant de requêtes (aucun N+1)', function (): void {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/admin/events')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->actingAs($this->superAdmin);

        $first = Event::factory()->create(['slug' => 'culte-1']);
        AdminFixtures::visitor(['2026-09-06'])->visits()->update(['event_id' => $first->id]);

        // Premier appel hors mesure : il remplit le cache des paramètres (URL publique du lien).
        $this->getJson('/api/admin/events')->assertOk();
        $before = $count();

        foreach (range(2, 10) as $number) {
            $event = Event::factory()->create(['slug' => 'culte-'.$number]);
            AdminFixtures::visitor([sprintf('2026-09-%02d', $number)])->visits()->update(['event_id' => $event->id]);
        }

        expect($count())->toBe($before)
            ->and($this->getJson('/api/admin/events')->json('data'))->toHaveCount(10);
    });

    it('compte zéro visite pour un événement neuf et reste lisible par un lecteur', function (): void {
        $event = Event::factory()->create(['slug' => 'neuf']);

        $this->actingAs(User::factory()->lecteur()->create())
            ->getJson('/api/admin/events')
            ->assertOk()
            ->assertJsonPath('data.0.id', $event->id)
            ->assertJsonPath('data.0.visits_count', 0)
            ->assertJsonPath('data.0.visitors_count', 0);
    });
});

describe('création', function (): void {
    it('crée un événement, renvoie son lien et journalise event.created', function (): void {
        app(SettingsService::class)->set('public_url', 'https://registre.newinechurch.org');

        $response = $this->actingAs($this->superAdmin)
            ->postJson('/api/admin/events', [
                'name' => 'Culte spécial du 4 octobre',
                'slug' => 'culte-4-octobre',
                'event_date' => '2026-10-04',
                'active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'culte-4-octobre')
            ->assertJsonPath('data.event_date', '2026-10-04')
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.url', 'https://registre.newinechurch.org/e/culte-4-octobre')
            ->assertJsonPath('data.visits_count', 0)
            ->assertJsonPath('data.visitors_count', 0);

        $event = Event::query()->sole();

        expect($event->name)->toBe('Culte spécial du 4 octobre')
            ->and($event->created_by)->toBe($this->superAdmin->id)
            ->and($response->json('data.id'))->toBe($event->id);

        $log = AuditLog::query()->sole();

        expect($log->action)->toBe('event.created')
            ->and($log->subject_type)->toBe('event')
            ->and($log->subject_id)->toBe($event->id)
            ->and($log->user_id)->toBe($this->superAdmin->id)
            ->and($log->meta)->toBe(['slug' => 'culte-4-octobre', 'active' => true]);
    });

    it('accepte un événement sans date et actif par défaut', function (): void {
        $this->actingAs($this->superAdmin)
            ->postJson('/api/admin/events', ['name' => 'Évangélisation de quartier', 'slug' => 'evangelisation'])
            ->assertCreated()
            ->assertJsonPath('data.event_date', null)
            ->assertJsonPath('data.active', true);

        expect(Event::query()->sole()->event_date)->toBeNull();
    });

    it('refuse un slug déjà pris (422 sur slug), sans créer de doublon', function (): void {
        Event::factory()->create(['slug' => 'culte-4-octobre']);

        $this->actingAs($this->superAdmin)
            ->postJson('/api/admin/events', ['name' => 'Un autre culte', 'slug' => 'culte-4-octobre'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);

        expect(Event::query()->count())->toBe(1)
            ->and(AuditLog::query()->count())->toBe(0);
    });

    it('refuse un slug mal formé (422 sur slug)', function (string $slug): void {
        $this->actingAs($this->superAdmin)
            ->postJson('/api/admin/events', ['name' => 'Culte', 'slug' => $slug])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);

        expect(Event::query()->count())->toBe(0);
    })->with([
        'majuscules' => ['Culte-Special'],
        'espace' => ['culte special'],
        'accent' => ['culte-spécial'],
        'tiret initial' => ['-culte'],
        'tiret final' => ['culte-'],
        'tirets doublés' => ['culte--special'],
        'point' => ['culte.special'],
        'barre oblique' => ['culte/special'],
        'vide' => [''],
        'trop long' => ['a-culte-vraiment-tres-tres-long-pour-depasser-la-limite-de-soixante'],
    ]);

    it('accepte les slugs conformes au contrat', function (string $slug): void {
        $this->actingAs($this->superAdmin)
            ->postJson('/api/admin/events', ['name' => 'Culte', 'slug' => $slug])
            ->assertCreated()
            ->assertJsonPath('data.slug', $slug);
    })->with([
        'un mot' => ['culte'],
        'chiffres' => ['4octobre2026'],
        'plusieurs segments' => ['culte-special-du-4-octobre'],
        'limite de longueur' => ['culte-vraiment-tres-tres-long-pour-atteindre-soixante-caract'],
    ]);

    it('refuse un nom manquant, vide ou trop long, et une date mal formée', function (array $payload, string $field): void {
        $this->actingAs($this->superAdmin)
            ->postJson('/api/admin/events', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    })->with([
        'nom manquant' => [['slug' => 'culte'], 'name'],
        'nom vide' => [['name' => '', 'slug' => 'culte'], 'name'],
        'nom trop long' => [['name' => str_repeat('a', 121), 'slug' => 'culte'], 'name'],
        'lien manquant' => [['name' => 'Culte'], 'slug'],
        'date non ISO' => [['name' => 'Culte', 'slug' => 'culte', 'event_date' => '04/10/2026'], 'event_date'],
        'date impossible' => [['name' => 'Culte', 'slug' => 'culte', 'event_date' => '2026-02-31'], 'event_date'],
    ]);
});

describe('modification', function (): void {
    it('modifie le nom ET le lien, et journalise event.updated', function (): void {
        $event = Event::factory()->create(['name' => 'Culte', 'slug' => 'culte', 'event_date' => '2026-10-04']);

        $this->actingAs($this->superAdmin)
            ->patchJson("/api/admin/events/{$event->id}", [
                'name' => 'Culte spécial du 4 octobre',
                'slug' => 'culte-4-octobre',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Culte spécial du 4 octobre')
            ->assertJsonPath('data.slug', 'culte-4-octobre')
            ->assertJsonPath('data.event_date', '2026-10-04');

        $log = AuditLog::query()->sole();

        expect($log->action)->toBe('event.updated')
            ->and($log->subject_type)->toBe('event')
            ->and($log->subject_id)->toBe($event->id)
            ->and($log->meta)->toBe([
                'fields' => ['name', 'slug'],
                'slug' => 'culte-4-octobre',
                'previous_slug' => 'culte',
            ]);
    });

    it('désactive un événement (son lien public cesse de répondre)', function (): void {
        $event = Event::factory()->create(['slug' => 'culte-4-octobre']);

        $this->getJson('/api/public/events/culte-4-octobre')->assertOk();

        $this->actingAs($this->superAdmin)
            ->patchJson("/api/admin/events/{$event->id}", ['active' => false])
            ->assertOk()
            ->assertJsonPath('data.active', false);

        $this->getJson('/api/public/events/culte-4-octobre')
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    });

    it('accepte de renvoyer le slug inchangé (aucun conflit avec lui-même, aucun journal)', function (): void {
        $event = Event::factory()->create(['name' => 'Culte', 'slug' => 'culte']);

        $this->actingAs($this->superAdmin)
            ->patchJson("/api/admin/events/{$event->id}", ['name' => 'Culte', 'slug' => 'culte'])
            ->assertOk()
            ->assertJsonPath('data.slug', 'culte');

        expect(AuditLog::query()->count())->toBe(0);
    });

    it('efface la date d\'un événement (event_date à null)', function (): void {
        $event = Event::factory()->create(['event_date' => '2026-10-04']);

        $this->actingAs($this->superAdmin)
            ->patchJson("/api/admin/events/{$event->id}", ['event_date' => null])
            ->assertOk()
            ->assertJsonPath('data.event_date', null);
    });

    it('refuse un slug déjà pris par un autre événement (422)', function (): void {
        Event::factory()->create(['slug' => 'culte-4-octobre']);
        $event = Event::factory()->create(['slug' => 'evangelisation']);

        $this->actingAs($this->superAdmin)
            ->patchJson("/api/admin/events/{$event->id}", ['slug' => 'culte-4-octobre'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);

        expect($event->fresh()?->slug)->toBe('evangelisation');
    });

    it('répond 404 pour un identifiant inconnu ou non numérique', function (string $id): void {
        $this->actingAs($this->superAdmin)
            ->patchJson("/api/admin/events/{$id}", ['name' => 'Culte'])
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    })->with(['999999', 'abc']);
});

describe('suppression', function (): void {
    it('supprime un événement sans visite et journalise event.deleted', function (): void {
        $event = Event::factory()->create(['slug' => 'culte-4-octobre']);

        $this->actingAs($this->superAdmin)
            ->deleteJson("/api/admin/events/{$event->id}")
            ->assertNoContent();

        expect(Event::query()->count())->toBe(0);

        $log = AuditLog::query()->sole();

        expect($log->action)->toBe('event.deleted')
            ->and($log->subject_type)->toBe('event')
            ->and($log->meta)->toBe(['slug' => 'culte-4-octobre']);
    });

    it('refuse 409 event_has_visits quand des visites y sont rattachées et invite à désactiver', function (): void {
        $event = Event::factory()->create(['slug' => 'culte-4-octobre']);
        $visitor = AdminFixtures::visitor(['2026-09-06']);
        $visitor->visits()->update(['event_id' => $event->id]);

        $this->actingAs($this->superAdmin)
            ->deleteJson("/api/admin/events/{$event->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'event_has_visits')
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'ésactivez'));

        expect(Event::query()->count())->toBe(1)
            ->and(AuditLog::query()->count())->toBe(0);
    });

    it('conserve la visite si l\'événement disparaît par ailleurs (event_id remis à null)', function (): void {
        $event = Event::factory()->create();
        $visitor = AdminFixtures::visitor(['2026-09-06']);
        $visitor->visits()->update(['event_id' => $event->id]);

        // Suppression directe en base (contourne la garde applicative) : la clé étrangère
        // remet event_id à NULL au lieu de supprimer la visite.
        $event->delete();

        expect(Visit::query()->count())->toBe(1)
            ->and(Visit::query()->sole()->event_id)->toBeNull();
    });
});

describe('permissions', function (): void {
    dataset('routes événements', [
        'liste' => ['GET', 'events', [], 'visitors.view', 200],
        'création' => ['POST', 'events', ['name' => 'Culte', 'slug' => 'nouveau-culte'], 'events.manage', 201],
        'modification' => ['PATCH', 'events/{event}', ['name' => 'Culte renommé'], 'events.manage', 200],
        'suppression' => ['DELETE', 'events/{event}', [], 'events.manage', 204],
    ]);

    it('applique les abilities du contrat à chaque route', function (string $method, string $uri, array $payload, string $ability, int $success, Role $role): void {
        $event = Event::factory()->create(['slug' => 'culte-4-octobre']);
        $user = User::factory()->role($role)->create();
        $uri = str_replace('{event}', (string) $event->id, $uri);

        $response = $this->actingAs($user)->json($method, '/api/admin/'.$uri, $payload);

        if ($role->allows($ability)) {
            expect($response->getStatusCode())->toBe($success, "{$role->value} {$method} {$uri} : ".$response->baseResponse->getContent());
        } else {
            $response->assertForbidden()->assertJsonPath('code', 'forbidden');
        }
    })->with('routes événements')->with([
        'lecteur' => [Role::Lecteur],
        'moderateur' => [Role::Moderateur],
        'super_admin' => [Role::SuperAdmin],
    ]);

    it('refuse un invité (401)', function (string $method, string $uri, array $payload): void {
        $event = Event::factory()->create();

        $this->json($method, '/api/admin/'.str_replace('{event}', (string) $event->id, $uri), $payload)
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Non authentifié.', 'code' => 'unauthenticated']);
    })->with('routes événements');

    it('refuse l\'écriture à un super_admin désactivé (401)', function (): void {
        $inactive = User::factory()->superAdmin()->inactive()->create();

        $this->actingAs($inactive)
            ->postJson('/api/admin/events', ['name' => 'Culte', 'slug' => 'culte'])
            ->assertUnauthorized();
    });
});
