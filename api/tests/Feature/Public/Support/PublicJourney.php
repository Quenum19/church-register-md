<?php

namespace Tests\Feature\Public\Support;

use App\Enums\PhoneCountry;
use App\Models\Family;
use App\Models\Visitor;
use App\Services\Journey\VisitTokenService;
use Carbon\CarbonImmutable;
use Database\Seeders\FamilySeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Aides des tests de l'API publique (parcours visiteur). À utiliser avec uses(PublicJourney::class).
 *
 * Numéro de référence : « 07 00 00 00 00 » (CI) = +2250700000000.
 */
trait PublicJourney
{
    protected string $phone = '07 00 00 00 00';

    protected string $e164 = '+2250700000000';

    /** Les 7 familles et les rotations 2025-11 → 2036-12 (septembre 2026 = Force, octobre = Honneur). */
    protected function seedFamilies(): void
    {
        $this->seed(FamilySeeder::class);
    }

    protected function familyId(string $name): int
    {
        return (int) Family::query()->where('name', $name)->valueOrFail('id');
    }

    /** Se place à une date et une heure d'Abidjan. */
    protected function travelToAbidjan(string $date, string $time = '10:00'): void
    {
        $this->travelTo(CarbonImmutable::parse("{$date} {$time}", 'Africa/Abidjan'));
    }

    protected function identify(?string $phone = null, string $country = 'CI'): TestResponse
    {
        return $this->postJson('/api/public/identify', ['country' => $country, 'phone' => $phone ?? $this->phone]);
    }

    /** Identifie le numéro et renvoie le jeton de parcours (étape 1, 2 ou 3 attendue). */
    protected function tokenFor(?string $phone = null, string $country = 'CI'): string
    {
        $token = $this->identify($phone, $country)->assertOk()->json('session_token');

        expect($token)->toBeString()->not->toBeEmpty();

        return $token;
    }

    /** Jeton émis directement par le service (scénarios impossibles via /identify). */
    protected function issueToken(string $e164, int $step, PhoneCountry $country = PhoneCountry::CI): string
    {
        return app(VisitTokenService::class)->issue($e164, $country, $step);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function visit1Answers(array $overrides = []): array
    {
        return array_replace([
            'full_name' => 'Aya Kouassi',
            'commune' => 'Cocody',
            'quartier' => 'Angré',
            'source' => 'bouche_a_oreille',
            'source_other' => null,
            'invited_by' => null,
            'inviter_family_id' => null,
            'whatsapp' => null,
            'whatsapp_same_as_phone' => false,
            'wants_whatsapp_group' => false,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function visit2Answers(array $overrides = []): array
    {
        return array_replace(['return_reasons' => ['enseignement'], 'return_reasons_other' => null], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function visit3Answers(array $overrides = []): array
    {
        return array_replace(['visit_reason' => 'devenir_membre', 'visit_reason_other' => null], $overrides);
    }

    /**
     * @param  array<string, mixed>|mixed  $answers
     */
    protected function postVisit(string $token, mixed $answers, ?bool $consent = true, ?string $key = null): TestResponse
    {
        $payload = [
            'session_token' => $token,
            'idempotency_key' => $key ?? (string) Str::uuid(),
            'answers' => $answers,
        ];

        if ($consent !== null) {
            $payload['consent'] = $consent;
        }

        return $this->postJson('/api/public/visits', $payload);
    }

    /** Prospect « Aya Kouassi » (1re visite le 13/09/2026) et son jeton d'étape 2. */
    protected function secondStepToken(): string
    {
        Visitor::factory()->prospect(CarbonImmutable::parse('2026-09-13'))->create([
            'phone' => $this->e164,
            'full_name' => 'Aya Kouassi',
        ]);

        return $this->tokenFor();
    }

    /** Visiteur récurrent (2 visites depuis le 02/08/2026) et son jeton d'étape 3. */
    protected function thirdStepToken(): string
    {
        Visitor::factory()->recurrent(CarbonImmutable::parse('2026-08-02'))->create(['phone' => $this->e164]);

        return $this->tokenFor();
    }

    /** Enregistre la 1re visite du numéro de référence (aujourd'hui) et renvoie la réponse. */
    protected function registerFirstVisit(array $overrides = [], ?string $key = null): TestResponse
    {
        return $this->postVisit($this->tokenFor(), $this->visit1Answers($overrides), true, $key)->assertCreated();
    }

    /**
     * Simule une requête concurrente : `$insert` s'exécute juste avant l'insertion du modèle
     * (une seule fois), dans la transaction de la requête testée.
     *
     * @param  class-string<Model>  $model
     */
    protected function beforeCreating(string $model, \Closure $insert): void
    {
        $fired = false;

        $model::creating(function ($created) use (&$fired, $insert): void {
            if (! $fired) {
                $fired = true;
                $insert($created);
            }
        });
    }

    /** Aucune session, aucun cookie. */
    protected function assertStateless(TestResponse $response): TestResponse
    {
        $response->assertHeaderMissing('Set-Cookie');

        expect($response->headers->getCookies())->toBe([])
            ->and($response->baseRequest->hasSession())->toBeFalse();

        return $response;
    }

    /**
     * Réponse publique conforme à la liste blanche de sa route, sans session ni cookie,
     * et dont le corps ne contient aucune des valeurs personnelles données.
     *
     * @param  list<string>  $personal
     */
    protected function assertNoPersonalData(TestResponse $response, array $personal): TestResponse
    {
        $this->assertStateless($response);

        $body = (string) $response->getContent();
        $json = $response->json();

        expect($json)->toBeArray();

        $keys = array_keys($json);

        if ($response->isSuccessful()) {
            $allowed = match (true) {
                array_key_exists('step', $json) => ['step', 'session_token', 'expires_in'],
                array_key_exists('visit_number', $json) => ['visit_number', 'family', 'completed'],
                default => ['church_name', 'public_url', 'verse', 'current_family', 'families'],
            };

            expect($keys)->toBe($allowed);

            if (array_key_exists('visit_number', $json) && $json['family'] !== null) {
                expect(array_keys($json['family']))->toBe(['id', 'name']);
            }
        } else {
            expect(array_diff($keys, ['message', 'code', 'errors']))->toBe([]);

            foreach ($json['errors'] ?? [] as $messages) {
                expect($messages)->toBeArray()->each->toBeString();
            }
        }

        foreach ($personal as $value) {
            $escaped = trim((string) json_encode($value), '"');

            expect(str_contains($body, $value))->toBeFalse("La réponse contient « {$value} ».")
                ->and(str_contains($body, $escaped))->toBeFalse("La réponse contient « {$value} » (échappé).");
        }

        return $response;
    }
}
