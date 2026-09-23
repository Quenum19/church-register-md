<?php

use App\Models\Member;
use App\Models\User;
use Tests\Feature\Admin\Support\AdminFixtures;

beforeEach(function (): void {
    $this->travelTo(AdminFixtures::now());
    AdminFixtures::families();
    $this->admin = User::factory()->superAdmin()->create(['name' => 'Jean Admin']);
    $this->actingAs(User::factory()->lecteur()->create());
});

it('liste uniquement les membres au format VisitorSummary + conversion', function (): void {
    $member = AdminFixtures::visitor(['2026-06-07', '2026-06-14', '2026-07-05'], [
        'full_name' => 'Awa Koné', 'phone' => '+2250700000001', 'whatsapp' => null, 'commune' => 'Cocody', 'quartier' => 'Angré',
    ], member: true, convertedBy: $this->admin);
    AdminFixtures::visitor(['2026-07-01', '2026-08-02', '2026-09-03']);
    AdminFixtures::visitor(['2026-09-01']);

    $this->getJson('/api/admin/members')
        ->assertOk()
        ->assertExactJson([
            'data' => [[
                'id' => $member->id,
                'full_name' => 'Awa Koné',
                'phone' => '+2250700000001',
                'whatsapp' => null,
                'commune' => 'Cocody',
                'quartier' => 'Angré',
                'status' => 'membre',
                'visit_count' => 3,
                'first_visit_date' => '2026-06-07',
                'last_visit_date' => '2026-07-05',
                'created_at' => '2026-06-07T10:00:00+00:00',
                'converted_at' => '2026-09-22T10:00:00+00:00',
                'converted_by' => ['id' => $this->admin->id, 'name' => 'Jean Admin'],
            ]],
            'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1],
        ]);
});

it('trie du plus récemment converti au plus ancien et gère un auteur supprimé', function (): void {
    $older = AdminFixtures::visitor(['2026-01-04', '2026-01-11', '2026-01-18'], member: true);
    $newer = AdminFixtures::visitor(['2026-02-01', '2026-02-08', '2026-02-15'], member: true, convertedBy: $this->admin);
    Member::query()->where('visitor_id', $older->id)->update(['converted_at' => AdminFixtures::now()->subMonth()]);

    $this->getJson('/api/admin/members')
        ->assertJsonPath('data.0.id', $newer->id)
        ->assertJsonPath('data.1.id', $older->id)
        ->assertJsonPath('data.1.converted_by', null);
});

it('recherche parmi les membres (nom, téléphone)', function (): void {
    $awa = AdminFixtures::visitor(['2026-06-07', '2026-06-14', '2026-07-05'], ['full_name' => 'Awa Koné', 'phone' => '+2250700000001'], member: true);
    AdminFixtures::visitor(['2026-06-01', '2026-06-08', '2026-06-15'], ['full_name' => 'Yao Kouassi', 'phone' => '+2250102030405'], member: true);
    AdminFixtures::visitor(['2026-09-01'], ['full_name' => 'Awa Traoré']);

    $this->getJson('/api/admin/members?search=awa')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $awa->id);
    $this->getJson('/api/admin/members?search='.urlencode('+225 07 00'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $awa->id);
    $this->getJson('/api/admin/members?search='.urlencode('%'))->assertJsonCount(0, 'data');
});

it('pagine et valide les paramètres', function (): void {
    $this->getJson('/api/admin/members')
        ->assertExactJson(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 0]]);

    $this->getJson('/api/admin/members?per_page=0')->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
    $this->getJson('/api/admin/members?per_page=101')->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
    $this->getJson('/api/admin/members?search='.str_repeat('a', 101))->assertUnprocessable()->assertJsonValidationErrors(['search']);
});
