<?php

use App\Enums\Source;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitorNote;
use App\Queries\DashboardStatsQuery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\Support\AdminFixtures;

beforeEach(function (): void {
    $this->travelTo(AdminFixtures::now());
    $this->families = AdminFixtures::families();
    $this->moderator = User::factory()->moderateur()->create(['name' => 'Marie Modératrice']);
    $this->admin = User::factory()->superAdmin()->create(['name' => 'Jean Admin']);
});

describe('GET /visitors/{id}', function (): void {
    it('renvoie la fiche exactement au format du contrat', function (): void {
        $visitor = AdminFixtures::visitor(['2026-08-02', '2026-09-06'], [
            'full_name' => 'Awa Koné',
            'phone' => '+2250700000001',
            'whatsapp' => null,
            'commune' => 'Cocody',
            'quartier' => 'Riviera 2',
            'source' => Source::InviteMembre,
            'source_other' => null,
            'invited_by' => 'Marie K.',
            'inviter_family_id' => $this->families['Richesse']->id,
            'wants_whatsapp_group' => false,
            'consent_at' => AdminFixtures::now()->setDate(2026, 8, 2)->setTime(9, 30),
        ]);
        [$visit1, $visit2] = $visitor->visits()->get()->all();
        $visit2->forceFill(['answers' => ['return_reasons' => ['enseignement', 'accueil'], 'return_reasons_other' => null]])->save();

        $old = VisitorNote::factory()->for($visitor)->for($this->moderator, 'author')->create([
            'body' => 'Première note', 'created_at' => AdminFixtures::now()->subDays(2),
        ]);
        $recent = VisitorNote::factory()->for($visitor)->for($this->admin, 'author')->create([
            'body' => 'Note récente', 'created_at' => AdminFixtures::now()->subHour(),
        ]);

        $response = $this->actingAs($this->moderator)->getJson("/api/admin/visitors/{$visitor->id}")->assertOk();

        $response->assertExactJson(['data' => [
            'id' => $visitor->id,
            'full_name' => 'Awa Koné',
            'phone' => '+2250700000001',
            'whatsapp' => null,
            'commune' => 'Cocody',
            'quartier' => 'Riviera 2',
            'status' => 'recurrent',
            'visit_count' => 2,
            'first_visit_date' => '2026-08-02',
            'last_visit_date' => '2026-09-06',
            'created_at' => '2026-08-02T10:00:00+00:00',
            'source' => 'invite_membre',
            'source_other' => null,
            'invited_by' => 'Marie K.',
            'inviter_family' => ['id' => $this->families['Richesse']->id, 'name' => 'Richesse'],
            'wants_whatsapp_group' => false,
            'consent_at' => '2026-08-02T09:30:00+00:00',
            'visits' => [
                ['id' => $visit1->id, 'visit_number' => 1, 'visit_date' => '2026-08-02',
                    'family' => ['id' => $this->families['Sagesse']->id, 'name' => 'Sagesse'], 'answers' => []],
                ['id' => $visit2->id, 'visit_number' => 2, 'visit_date' => '2026-09-06',
                    'family' => ['id' => $this->families['Force']->id, 'name' => 'Force'],
                    'answers' => ['return_reasons' => ['enseignement', 'accueil'], 'return_reasons_other' => null]],
            ],
            'notes' => [
                ['id' => $recent->id, 'body' => 'Note récente', 'author' => ['id' => $this->admin->id, 'name' => 'Jean Admin'],
                    'created_at' => '2026-09-22T09:00:00+00:00', 'can_delete' => false],
                ['id' => $old->id, 'body' => 'Première note', 'author' => ['id' => $this->moderator->id, 'name' => 'Marie Modératrice'],
                    'created_at' => '2026-09-20T10:00:00+00:00', 'can_delete' => true],
            ],
            'member' => null,
        ]]);

        // `answers` de la visite 1 : un objet JSON vide, jamais un tableau.
        expect($response->getContent())->toContain('"answers":{}')->not->toContain('"answers":[]');
    });

    it('autorise un super_admin à supprimer toutes les notes (can_delete)', function (): void {
        $visitor = AdminFixtures::visitor(['2026-09-01']);
        VisitorNote::factory()->for($visitor)->for($this->moderator, 'author')->create();
        VisitorNote::factory()->for($visitor)->create(['user_id' => null]);

        $this->actingAs($this->admin)
            ->getJson("/api/admin/visitors/{$visitor->id}")
            ->assertJsonPath('data.notes.0.can_delete', true)
            ->assertJsonPath('data.notes.1.can_delete', true);

        $this->actingAs(User::factory()->lecteur()->create())
            ->getJson("/api/admin/visitors/{$visitor->id}")
            ->assertJsonPath('data.notes.0.can_delete', false)
            ->assertJsonPath('data.notes.1.can_delete', false);
    });

    it('renvoie une note sans auteur (compte supprimé) avec author = null', function (): void {
        $visitor = AdminFixtures::visitor(['2026-09-01']);
        VisitorNote::factory()->for($visitor)->create(['user_id' => null]);

        $this->actingAs($this->moderator)
            ->getJson("/api/admin/visitors/{$visitor->id}")
            ->assertJsonPath('data.notes.0.author', null);
    });

    it('renvoie la conversion d\'un membre', function (): void {
        $visitor = AdminFixtures::visitor(['2026-06-07', '2026-06-14', '2026-07-05'], member: true, convertedBy: $this->admin);

        $this->actingAs($this->moderator)
            ->getJson("/api/admin/visitors/{$visitor->id}")
            ->assertJsonPath('data.status', 'membre')
            ->assertJsonPath('data.visit_count', 3)
            ->assertJsonPath('data.member', [
                'converted_at' => '2026-09-22T10:00:00+00:00',
                'converted_by' => ['id' => $this->admin->id, 'name' => 'Jean Admin'],
            ])
            ->assertJsonPath('data.visits.2.answers.visit_reason', fn (mixed $reason): bool => is_string($reason));
    });

    it('répond 404 not_found pour un identifiant inexistant ou non numérique', function (string $id): void {
        $this->actingAs($this->moderator)
            ->getJson("/api/admin/visitors/{$id}")
            ->assertNotFound()
            ->assertExactJson(['message' => 'Ressource introuvable.', 'code' => 'not_found']);
    })->with(['999999', 'abc', '12abc', '-1', '1.5']);

    it('charge la fiche en un nombre constant de requêtes', function (): void {
        $visitor = AdminFixtures::visitor(['2026-07-01', '2026-08-02', '2026-09-03'], member: true, convertedBy: $this->admin);
        VisitorNote::factory()->count(5)->for($visitor)->create();

        $this->actingAs($this->moderator);
        DB::enableQueryLog();
        $this->getJson("/api/admin/visitors/{$visitor->id}")->assertOk();

        // visiteur + famille de l'invitant + visites + familles + notes + auteurs + membre + auteur de la conversion
        expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(8);
    });
});

describe('PATCH /visitors/{id}', function (): void {
    beforeEach(function (): void {
        $this->visitor = AdminFixtures::visitor(['2026-09-01'], [
            'full_name' => 'Awa Koné',
            'phone' => '+2250700000001',
            'commune' => 'Cocody',
            'quartier' => 'Angré',
            'invited_by' => null,
            'whatsapp' => null,
            'wants_whatsapp_group' => false,
        ]);
        $this->actingAs($this->moderator);
    });

    it('modifie les champs de la liste blanche et renvoie la fiche', function (): void {
        $this->patchJson("/api/admin/visitors/{$this->visitor->id}", [
            'full_name' => '  Awa Koné-Bamba ',
            'commune' => 'Marcory',
            'quartier' => 'Zone 4',
            'invited_by' => 'Pasteur Yao',
            'whatsapp' => ['country' => 'CI', 'number' => '05 00 00 00 01'],
            'wants_whatsapp_group' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $this->visitor->id)
            ->assertJsonPath('data.full_name', 'Awa Koné-Bamba')
            ->assertJsonPath('data.commune', 'Marcory')
            ->assertJsonPath('data.quartier', 'Zone 4')
            ->assertJsonPath('data.invited_by', 'Pasteur Yao')
            ->assertJsonPath('data.whatsapp', '+2250500000001')
            ->assertJsonPath('data.wants_whatsapp_group', true)
            ->assertJsonPath('data.visits.0.visit_number', 1);

        $log = AuditLog::query()->sole();

        expect($log->action)->toBe('visitor.updated')
            ->and($log->user_id)->toBe($this->moderator->id)
            ->and($log->subject_type)->toBe('visitor')
            ->and($log->subject_id)->toBe($this->visitor->id)
            // Noms des champs modifiés uniquement, jamais leurs valeurs.
            ->and($log->meta)->toBe(['fields' => ['full_name', 'whatsapp', 'commune', 'quartier', 'invited_by', 'wants_whatsapp_group']])
            ->and(json_encode($log->meta))->not->toContain('Marcory')->not->toContain('2250500000001');
    });

    it('ignore les champs hors liste blanche (téléphone, statut, source…)', function (): void {
        $this->patchJson("/api/admin/visitors/{$this->visitor->id}", [
            'phone' => '+2250799999999',
            'status' => 'membre',
            'source' => 'autre',
            'consent_at' => null,
            'commune' => 'Plateau',
        ])->assertOk()->assertJsonPath('data.status', 'prospect');

        $fresh = $this->visitor->fresh();

        expect($fresh->phone)->toBe('+2250700000001')
            ->and($fresh->status->value)->toBe('prospect')
            ->and($fresh->commune)->toBe('Plateau')
            ->and(AuditLog::query()->sole()->meta)->toBe(['fields' => ['commune']]);
    });

    it('normalise le WhatsApp en E.164 selon le pays', function (array $whatsapp, string $expected): void {
        $this->patchJson("/api/admin/visitors/{$this->visitor->id}", ['whatsapp' => $whatsapp])
            ->assertOk()
            ->assertJsonPath('data.whatsapp', $expected);
    })->with([
        'CI avec espaces' => [['country' => 'CI', 'number' => '07 00 00 00 02'], '+2250700000002'],
        'FR avec 0 initial' => [['country' => 'FR', 'number' => '06 12 34 56 78'], '+33612345678'],
        'autre pays' => [['country' => 'OTHER', 'number' => '+44 7400 123456'], '+447400123456'],
    ]);

    it('stocke NULL pour un WhatsApp null ou vide', function (mixed $whatsapp): void {
        $this->visitor->forceFill(['whatsapp' => '+2250700000001'])->save();

        $this->patchJson("/api/admin/visitors/{$this->visitor->id}", ['whatsapp' => $whatsapp])
            ->assertOk()
            ->assertJsonPath('data.whatsapp', null);

        expect($this->visitor->fresh()->whatsapp)->toBeNull();
    })->with([
        'null' => [null],
        'numéro vide' => [['country' => 'CI', 'number' => '']],
        'numéro fait d\'espaces' => [['country' => 'CI', 'number' => '   ']],
    ]);

    it('valide les champs (422)', function (array $payload, string $field): void {
        $this->patchJson("/api/admin/visitors/{$this->visitor->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation')
            ->assertJsonValidationErrors([$field]);

        expect(AuditLog::query()->count())->toBe(0);
    })->with([
        'nom vide' => [['full_name' => ''], 'full_name'],
        'nom trop long' => [['full_name' => str_repeat('a', 101)], 'full_name'],
        'commune vide' => [['commune' => '   '], 'commune'],
        'commune trop longue' => [['commune' => str_repeat('a', 81)], 'commune'],
        'quartier trop long' => [['quartier' => str_repeat('a', 81)], 'quartier'],
        'invitant trop long' => [['invited_by' => str_repeat('a', 101)], 'invited_by'],
        'groupe non booléen' => [['wants_whatsapp_group' => 'peut-être'], 'wants_whatsapp_group'],
        'WhatsApp invalide' => [['whatsapp' => ['country' => 'CI', 'number' => '07 00']], 'whatsapp.number'],
        'WhatsApp avec lettres' => [['whatsapp' => ['country' => 'CI', 'number' => '07 AB 00 00 00']], 'whatsapp.number'],
        'pays inconnu' => [['whatsapp' => ['country' => 'XX', 'number' => '0700000000']], 'whatsapp.country'],
        'pays manquant' => [['whatsapp' => ['number' => '0700000000']], 'whatsapp.country'],
        'WhatsApp en texte' => [['whatsapp' => '0700000000'], 'whatsapp'],
        'clé inattendue dans whatsapp' => [['whatsapp' => ['country' => 'CI', 'number' => '0700000000', 'x' => 1]], 'whatsapp'],
        'groupe sans WhatsApp' => [['wants_whatsapp_group' => true], 'whatsapp'],
    ]);

    it('refuse de retirer le WhatsApp d\'un visiteur qui veut rejoindre le groupe', function (): void {
        $this->visitor->forceFill(['whatsapp' => '+2250700000001', 'wants_whatsapp_group' => true])->save();

        $this->patchJson("/api/admin/visitors/{$this->visitor->id}", ['whatsapp' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['whatsapp']);

        $this->patchJson("/api/admin/visitors/{$this->visitor->id}", ['whatsapp' => null, 'wants_whatsapp_group' => false])
            ->assertOk()
            ->assertJsonPath('data.whatsapp', null);
    });

    it('efface l\'invitant avec null ou une chaîne vide', function (): void {
        $this->visitor->forceFill(['invited_by' => 'Marie'])->save();

        $this->patchJson("/api/admin/visitors/{$this->visitor->id}", ['invited_by' => ''])
            ->assertOk()
            ->assertJsonPath('data.invited_by', null);
    });

    it('ne journalise rien si aucun champ ne change', function (): void {
        $this->patchJson("/api/admin/visitors/{$this->visitor->id}", [])->assertOk();
        $this->patchJson("/api/admin/visitors/{$this->visitor->id}", ['commune' => 'Cocody', 'whatsapp' => null])->assertOk();

        expect(AuditLog::query()->count())->toBe(0);
    });

    it('répond 404 pour un visiteur inexistant', function (): void {
        $this->patchJson('/api/admin/visitors/999999', ['commune' => 'Plateau'])->assertNotFound();
    });
});

describe('DELETE /visitors/{id}', function (): void {
    it('supprime le visiteur, ses visites, notes et conversion en cascade', function (): void {
        $visitor = AdminFixtures::visitor(['2026-07-01', '2026-08-02', '2026-09-03'], member: true, convertedBy: $this->admin);
        VisitorNote::factory()->count(2)->for($visitor)->create();
        $other = AdminFixtures::visitor(['2026-09-01']);
        Cache::put(DashboardStatsQuery::CACHE_KEY, ['total_visitors' => 99], 300);

        $this->actingAs($this->admin)->deleteJson("/api/admin/visitors/{$visitor->id}")->assertNoContent();

        expect(Visitor::query()->pluck('id')->all())->toBe([$other->id])
            ->and(Visit::query()->where('visitor_id', $visitor->id)->count())->toBe(0)
            ->and(VisitorNote::query()->where('visitor_id', $visitor->id)->count())->toBe(0)
            ->and(Member::query()->where('visitor_id', $visitor->id)->count())->toBe(0)
            ->and(Visit::query()->where('visitor_id', $other->id)->count())->toBe(1)
            ->and(Cache::has(DashboardStatsQuery::CACHE_KEY))->toBeFalse();

        $log = AuditLog::query()->sole();

        expect($log->action)->toBe('visitor.deleted')
            ->and($log->subject_type)->toBe('visitor')
            ->and($log->subject_id)->toBe($visitor->id)
            ->and($log->user_id)->toBe($this->admin->id);

        $this->getJson("/api/admin/visitors/{$visitor->id}")->assertNotFound();
    });

    it('répond 404 pour un visiteur inexistant, sans journal', function (): void {
        $this->actingAs($this->admin)->deleteJson('/api/admin/visitors/999999')->assertNotFound();

        expect(AuditLog::query()->count())->toBe(0);
    });
});
