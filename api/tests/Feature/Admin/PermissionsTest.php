<?php

use App\Enums\Role;
use App\Models\User;
use App\Models\VisitorNote;
use Tests\Feature\Admin\Support\AdminFixtures;

/*
| Matrice des permissions de l'API admin « visiteurs » : chaque route × chaque rôle
| (contrat d'API §1), plus l'invité (401). Les requêtes autorisées reçoivent un corps valide
| et doivent aboutir avec le statut de succès du contrat.
*/

beforeEach(function (): void {
    $this->travelTo(AdminFixtures::now());
    $this->families = AdminFixtures::families();
    $this->potential = AdminFixtures::visitor(['2026-08-02', '2026-08-30', '2026-09-20']);
    $this->member = AdminFixtures::visitor(['2026-06-07', '2026-06-14', '2026-07-05'], member: true);
    // Note écrite par un autre compte : seul un super_admin peut la supprimer.
    $this->note = VisitorNote::factory()->for($this->potential)->create();

    $this->resolve = fn (string $value): string => strtr($value, [
        '{potential}' => (string) $this->potential->id,
        '{member}' => (string) $this->member->id,
        '{note}' => (string) $this->note->id,
    ]);
});

dataset('routes admin visiteurs', [
    'stats' => ['GET', 'stats', [], 'visitors.view', 200],
    'liste des visiteurs' => ['GET', 'visitors', [], 'visitors.view', 200],
    'fiche visiteur' => ['GET', 'visitors/{potential}', [], 'visitors.view', 200],
    'modification' => ['PATCH', 'visitors/{potential}', ['commune' => 'Marcory'], 'visitors.update', 200],
    'suppression' => ['DELETE', 'visitors/{potential}', [], 'visitors.delete', 204],
    'conversion' => ['POST', 'visitors/{potential}/convert', [], 'visitors.convert', 200],
    'annulation de conversion' => ['DELETE', 'visitors/{member}/convert', [], 'visitors.unconvert', 200],
    'ajout de note' => ['POST', 'visitors/{potential}/notes', ['body' => 'Appelé ce jour.'], 'notes.create', 201],
    'suppression de la note d\'un tiers' => ['DELETE', 'notes/{note}', [], 'note.delete', 204],
    'membres' => ['GET', 'members', [], 'visitors.view', 200],
    'export CSV' => ['GET', 'exports/visitors.csv', [], 'visitors.export', 200],
    'export Excel' => ['GET', 'exports/visitors.xlsx', [], 'visitors.export', 200],
    'export PDF' => ['GET', 'exports/visitors.pdf', [], 'visitors.export', 200],
    'familles' => ['GET', 'families', [], 'visitors.view', 200],
    'rotations' => ['GET', 'rotations?from=2026-01&months=3', [], 'visitors.view', 200],
    'modification de rotation' => ['PUT', 'rotations/2026/10', ['family' => 'Gloire'], 'rotations.manage', 200],
    'paramètres' => ['GET', 'settings', [], 'visitors.view', 200],
    'modification des paramètres' => ['PUT', 'settings', ['church_name' => 'Église de test'], 'settings.update', 200],
]);

dataset('rôles', [
    'lecteur' => [Role::Lecteur],
    'moderateur' => [Role::Moderateur],
    'super_admin' => [Role::SuperAdmin],
]);

it('applique les abilities du contrat à chaque route', function (string $method, string $uri, array $payload, string $ability, int $success, Role $role): void {
    $user = User::factory()->role($role)->create();

    if (isset($payload['family'])) {
        $payload = ['family_id' => $this->families[$payload['family']]->id];
    }

    // Suppression d'une note : son auteur ou un super_admin (ici, l'auteur est un tiers).
    $allowed = $ability === 'note.delete' ? $role === Role::SuperAdmin : $role->allows($ability);

    $response = $this->actingAs($user)->json($method, '/api/admin/'.($this->resolve)($uri), $payload);

    if ($allowed) {
        expect($response->getStatusCode())->toBe($success, "{$role->value} {$method} {$uri} : ".$response->baseResponse->getContent());
    } else {
        $response->assertForbidden()->assertJsonPath('code', 'forbidden');
    }
})->with('routes admin visiteurs')->with('rôles');

it('refuse un invité (401)', function (string $method, string $uri, array $payload): void {
    $this->json($method, '/api/admin/'.($this->resolve)($uri), $payload)
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Non authentifié.', 'code' => 'unauthenticated']);
})->with('routes admin visiteurs');

it('refuse un compte désactivé (401)', function (): void {
    $user = User::factory()->superAdmin()->inactive()->create();

    $this->actingAs($user)->getJson('/api/admin/stats')->assertUnauthorized();
});

it('laisse l\'auteur supprimer sa note quel que soit son rôle', function (Role $role): void {
    $author = User::factory()->role($role)->create();
    $note = VisitorNote::factory()->for($this->potential)->for($author, 'author')->create();

    $this->actingAs($author)->deleteJson("/api/admin/notes/{$note->id}")->assertNoContent();
})->with('rôles');
