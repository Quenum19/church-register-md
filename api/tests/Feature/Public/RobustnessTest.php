<?php

use App\Enums\VisitorStatus;
use App\Models\FamilyRotation;
use App\Models\Visit;
use App\Models\Visitor;
use App\Services\Journey\VisitRecorder;
use App\Services\Journey\VisitTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Feature\Public\Support\PublicJourney;

uses(PublicJourney::class);

beforeEach(function (): void {
    $this->seedFamilies();
    $this->travelToAbidjan('2026-09-22');
});

/*
|--------------------------------------------------------------------------
| Famille absente, contenu hostile
|--------------------------------------------------------------------------
*/

it('enregistre la visite sans famille et journalise une alerte quand le mois n\'a pas de rotation', function (): void {
    FamilyRotation::query()->where(['year' => 2026, 'month' => 9])->delete();
    Log::spy();

    $this->registerFirstVisit()
        ->assertExactJson(['visit_number' => 1, 'family' => null, 'completed' => false]);

    expect(Visit::query()->sole()->family_id)->toBeNull();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'rotation')
            && $context === ['year' => 2026, 'month' => 9]);
});

it('stocke le HTML tel quel sans jamais le renvoyer', function (): void {
    $html = '<script>alert("x")</script><img src=x onerror=alert(1)>';

    $response = $this->postVisit($this->tokenFor(), $this->visit1Answers([
        'full_name' => 'Aya <b>Kouassi</b>',
        'commune' => $html,
        'quartier' => '"><svg onload=alert(1)>',
        'source' => 'autre',
        'source_other' => $html,
    ]))->assertCreated();

    $visitor = Visitor::query()->sole();

    expect($visitor->full_name)->toBe('Aya <b>Kouassi</b>')
        ->and($visitor->commune)->toBe($html)
        ->and($visitor->quartier)->toBe('"><svg onload=alert(1)>')
        ->and($visitor->source_other)->toBe($html)
        ->and((string) $response->getContent())->not->toContain('script')->not->toContain('alert')->not->toContain('Kouassi');

    // Réponses de visite 2 : même traitement.
    $this->travelToAbidjan('2026-09-29');

    $response = $this->postVisit($this->tokenFor(), [
        'return_reasons' => ['autres'],
        'return_reasons_other' => $html,
    ], null)->assertCreated();

    expect(Visit::query()->where('visit_number', 2)->sole()->answers['return_reasons_other'])->toBe($html)
        ->and((string) $response->getContent())->not->toContain('alert');
});

it('refuse une chaîne qui n\'est pas de l\'UTF-8 valide (422, pas 500)', function (): void {
    $this->post('/api/public/visits', [
        'session_token' => $this->tokenFor(),
        'idempotency_key' => (string) Str::uuid(),
        'consent' => '1',
        'answers' => [...$this->visit1Answers(), 'full_name' => "Aya \xC3\x28", 'whatsapp_same_as_phone' => '0', 'wants_whatsapp_group' => '0'],
    ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['answers.full_name']);

    expect(Visitor::query()->count())->toBe(0);
});

it('accepte un envoi en formulaire classique (booléens « 1 » / « 0 »)', function (): void {
    $this->post('/api/public/visits', [
        'session_token' => $this->tokenFor(),
        'idempotency_key' => (string) Str::uuid(),
        'consent' => '1',
        'answers' => [...$this->visit1Answers(), 'whatsapp_same_as_phone' => '1', 'wants_whatsapp_group' => '1', 'whatsapp' => ''],
    ], ['Accept' => 'application/json'])->assertCreated();

    expect(Visitor::query()->sole()->whatsapp)->toBe($this->e164);
});

/*
|--------------------------------------------------------------------------
| Requêtes concurrentes simulées : toujours 409 (ou rejeu 200), jamais 500
|--------------------------------------------------------------------------
*/

it('déclare les index uniques qui arbitrent les requêtes concurrentes', function (): void {
    $indexes = collect(DB::select('SHOW INDEX FROM visits'))->pluck('Key_name')
        ->merge(collect(DB::select('SHOW INDEX FROM visitors'))->pluck('Key_name'))
        ->unique();

    expect($indexes)->toContain(VisitRecorder::INDEX_VISIT_DATE)
        ->toContain(VisitRecorder::INDEX_VISIT_NUMBER)
        ->toContain(VisitRecorder::INDEX_IDEMPOTENCY_KEY)
        ->toContain(VisitRecorder::INDEX_VISITOR_PHONE);
});

it('répond already_today quand une visite du jour est insérée entre-temps (index visitor_id + visit_date)', function (): void {
    $token = $this->secondStepToken();

    $this->beforeCreating(Visit::class, fn (Visit $visit) => DB::table('visits')->insert([
        'visitor_id' => $visit->visitor_id,
        'visit_number' => 3,
        'visit_date' => '2026-09-22',
        'answers' => '{}',
        'idempotency_key' => (string) Str::uuid(),
        'created_at' => now(),
    ]));

    $this->postVisit($token, $this->visit2Answers(), null)
        ->assertStatus(409)
        ->assertJsonPath('code', 'already_today');

    expect(Visit::query()->count())->toBe(1)
        ->and(Visitor::query()->sole()->status)->toBe(VisitorStatus::Prospect)
        ->and(app(VisitTokenService::class)->isConsumed(app(VisitTokenService::class)->decode($token)))->toBeFalse();
});

it('répond step_mismatch quand la même visite est insérée entre-temps (index visitor_id + visit_number)', function (): void {
    $token = $this->secondStepToken();

    $this->beforeCreating(Visit::class, fn (Visit $visit) => DB::table('visits')->insert([
        'visitor_id' => $visit->visitor_id,
        'visit_number' => 2,
        'visit_date' => '2026-09-21',
        'answers' => '{}',
        'idempotency_key' => (string) Str::uuid(),
        'created_at' => now(),
    ]));

    $this->postVisit($token, $this->visit2Answers(), null)
        ->assertStatus(409)
        ->assertJsonPath('code', 'step_mismatch');

    expect(Visit::query()->count())->toBe(1);
});

it('répond step_mismatch quand le visiteur est créé entre-temps (index visitors.phone)', function (): void {
    $token = $this->tokenFor();

    $this->beforeCreating(Visitor::class, fn (Visitor $visitor) => DB::table('visitors')->insert([
        'phone' => $visitor->phone,
        'full_name' => 'Requête concurrente',
        'commune' => 'Yopougon',
        'quartier' => 'Niangon',
        'source' => 'passage',
        'status' => 'prospect',
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    $this->postVisit($token, $this->visit1Answers())
        ->assertStatus(409)
        ->assertJsonPath('code', 'step_mismatch');

    expect(Visitor::query()->count())->toBe(0)->and(Visit::query()->count())->toBe(0);
});

it('répond idempotency_conflict quand la clé est prise entre-temps par un autre visiteur', function (): void {
    $other = Visitor::factory()->prospect(CarbonImmutable::parse('2026-09-06'))->create();
    $key = (string) Str::uuid();

    $this->beforeCreating(Visit::class, fn () => DB::table('visits')->insert([
        'visitor_id' => $other->id,
        'visit_number' => 2,
        'visit_date' => '2026-09-22',
        'answers' => '{}',
        'idempotency_key' => $key,
        'created_at' => now(),
    ]));

    $this->postVisit($this->tokenFor(), $this->visit1Answers(), true, $key)
        ->assertStatus(409)
        ->assertJsonPath('code', 'idempotency_conflict');
});

it('rejoue la requête jumelle (même clé) terminée pendant l\'attente du verrou', function (): void {
    $token = $this->secondStepToken();
    $visitor = Visitor::query()->sole();
    $key = (string) Str::uuid();
    $fired = false;

    // La requête jumelle enregistre la visite 2 pendant que celle-ci attend le verrou FOR UPDATE.
    DB::listen(function (QueryExecuted $query) use (&$fired, $visitor, $key): void {
        if (! $fired && str_contains($query->sql, 'for update')) {
            $fired = true;
            Visit::factory()->second()->for($visitor)->onDate('2026-09-22')->create(['idempotency_key' => $key]);
        }
    });

    $this->postVisit($token, $this->visit2Answers(), null, $key)
        ->assertOk()
        ->assertExactJson(['visit_number' => 2, 'family' => ['id' => $this->familyId('Force'), 'name' => 'Force'], 'completed' => false]);

    expect($fired)->toBeTrue()
        ->and(Visit::query()->count())->toBe(2);
});

it('répond 409 (et non 500) à un interblocage persistant de la base', function (): void {
    $this->beforeCreating(Visitor::class, function (): void {
        throw new DeadlockException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction', 40001);
    });

    $this->postVisit($this->tokenFor(), $this->visit1Answers())
        ->assertStatus(409)
        ->assertJsonPath('code', 'step_mismatch');

    expect(Visitor::query()->count())->toBe(0);
});

it('consomme le jeton de façon atomique : un second usage concurrent échoue', function (): void {
    $token = $this->tokenFor();
    $decoded = app(VisitTokenService::class)->decode($token);

    // Une requête concurrente consomme le même jeton pendant la transaction.
    $this->beforeCreating(Visit::class, fn () => app(VisitTokenService::class)->markConsumed($decoded));

    $this->postVisit($token, $this->visit1Answers())
        ->assertStatus(409)
        ->assertJsonPath('code', 'token_used');

    expect(Visitor::query()->count())->toBe(0)->and(Visit::query()->count())->toBe(0);
});
