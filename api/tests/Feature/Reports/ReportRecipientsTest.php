<?php

use App\Enums\Role;
use App\Mail\TestMail;
use App\Models\AuditLog;
use App\Models\ReportRecipient;
use App\Services\Reports\ReportDispatcher;
use App\Support\RateLimits;
use Database\Seeders\FamilySeeder;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Reports\Support\ReportData;

beforeEach(function (): void {
    $this->seed(FamilySeeder::class);
    $this->admin = userWithRole(Role::SuperAdmin, ['name' => 'Jean Kouassi', 'email' => 'jean.kouassi@exemple.test']);
    $this->sagesse = ReportData::family('Sagesse');
    $this->force = ReportData::family('Force');
});

describe('GET /api/admin/report-recipients', function (): void {
    it('trie les destinataires : globaux, puis par famille (ordre de rotation), puis par nom', function (): void {
        $zoe = ReportRecipient::factory()->forFamily($this->force)->create(['name' => 'Zoé', 'email' => 'zoe@exemple.test']);
        $alain = ReportRecipient::factory()->forFamily($this->force)->create(['name' => 'Alain', 'email' => 'alain@exemple.test']);
        $sara = ReportRecipient::factory()->forFamily($this->sagesse)->inactive()->create(['name' => 'Sara', 'email' => 'sara@exemple.test']);
        $pasteur = ReportRecipient::factory()->create(['name' => 'Pasteur', 'email' => 'pasteur@exemple.test']);
        $bureau = ReportRecipient::factory()->create(['name' => 'Bureau', 'email' => 'bureau@exemple.test']);

        $this->actingAs($this->admin)
            ->getJson('/api/admin/report-recipients')
            ->assertOk()
            ->assertExactJson(['data' => [
                ['id' => $bureau->id, 'family' => null, 'name' => 'Bureau', 'email' => 'bureau@exemple.test', 'active' => true],
                ['id' => $pasteur->id, 'family' => null, 'name' => 'Pasteur', 'email' => 'pasteur@exemple.test', 'active' => true],
                ['id' => $sara->id, 'family' => ['id' => $this->sagesse->id, 'name' => 'Sagesse'], 'name' => 'Sara', 'email' => 'sara@exemple.test', 'active' => false],
                ['id' => $alain->id, 'family' => ['id' => $this->force->id, 'name' => 'Force'], 'name' => 'Alain', 'email' => 'alain@exemple.test', 'active' => true],
                ['id' => $zoe->id, 'family' => ['id' => $this->force->id, 'name' => 'Force'], 'name' => 'Zoé', 'email' => 'zoe@exemple.test', 'active' => true],
            ]]);
    });
});

describe('POST /api/admin/report-recipients', function (): void {
    it('crée un destinataire de famille (201) et journalise', function (): void {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/admin/report-recipients', [
                'family_id' => $this->sagesse->id,
                'name' => '  Responsable Sagesse ',
                'email' => ' Responsable.Sagesse@Exemple.TEST ',
                'active' => true,
            ])
            ->assertCreated();

        $recipient = ReportRecipient::query()->sole();

        $response->assertExactJson(['data' => [
            'id' => $recipient->id,
            'family' => ['id' => $this->sagesse->id, 'name' => 'Sagesse'],
            'name' => 'Responsable Sagesse',
            'email' => 'responsable.sagesse@exemple.test',
            'active' => true,
        ]]);

        $log = AuditLog::query()->where('action', 'recipient.created')->sole();

        expect($log->user_id)->toBe($this->admin->id)
            ->and($log->subject_type)->toBe('recipient')
            ->and($log->subject_id)->toBe($recipient->id)
            ->and($log->meta)->toBe(['family_id' => $this->sagesse->id, 'active' => true]);
    });

    it('crée un destinataire global (family_id null), actif par défaut', function (): void {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/report-recipients', ['family_id' => null, 'name' => 'Pasteur', 'email' => 'pasteur@exemple.test'])
            ->assertCreated()
            ->assertJsonPath('data.family', null)
            ->assertJsonPath('data.active', true);
    });

    it('refuse un doublon global (family_id NULL), quelle que soit la casse (422 sur email)', function (string $email): void {
        ReportRecipient::factory()->create(['email' => 'pasteur@exemple.test']);

        $this->actingAs($this->admin)
            ->postJson('/api/admin/report-recipients', ['family_id' => null, 'name' => 'Pasteur bis', 'email' => $email])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation')
            ->assertJsonPath('errors.email', ['Cette adresse reçoit déjà ces rapports.']);

        expect(ReportRecipient::query()->count())->toBe(1);
    })->with(['pasteur@exemple.test', 'Pasteur@Exemple.test']);

    it('refuse un doublon dans la même famille', function (): void {
        ReportRecipient::factory()->forFamily($this->sagesse)->create(['email' => 'sagesse@exemple.test']);

        $this->actingAs($this->admin)
            ->postJson('/api/admin/report-recipients', ['family_id' => $this->sagesse->id, 'name' => 'Doublon', 'email' => 'sagesse@exemple.test'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    });

    it('accepte la même adresse pour une autre famille ou en global', function (): void {
        ReportRecipient::factory()->forFamily($this->sagesse)->create(['email' => 'pasteur@exemple.test']);

        $this->actingAs($this->admin)
            ->postJson('/api/admin/report-recipients', ['family_id' => $this->force->id, 'name' => 'Pasteur', 'email' => 'pasteur@exemple.test'])
            ->assertCreated();

        $this->actingAs($this->admin)
            ->postJson('/api/admin/report-recipients', ['family_id' => null, 'name' => 'Pasteur', 'email' => 'pasteur@exemple.test'])
            ->assertCreated();

        expect(ReportRecipient::query()->count())->toBe(3);
    });

    it('valide les champs (422)', function (array $payload, string $field): void {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/report-recipients', [
                ...['family_id' => null, 'name' => 'Pasteur', 'email' => 'pasteur@exemple.test', 'active' => true],
                ...$payload,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation')
            ->assertJsonValidationErrors([$field]);

        expect(ReportRecipient::query()->count())->toBe(0);
    })->with([
        'email invalide' => [['email' => 'pas-une-adresse'], 'email'],
        'email sans domaine' => [['email' => 'pasteur@'], 'email'],
        'email sans TLD' => [['email' => 'pasteur@exemple'], 'email'],
        'email avec espace' => [['email' => 'pas teur@exemple.test'], 'email'],
        'email > 190 (RFC valide)' => [['email' => 'pasteur@'.implode('.', array_fill(0, 4, str_repeat('x', 45))).'.test'], 'email'],
        'email manquant' => [['email' => null], 'email'],
        'nom manquant' => [['name' => ''], 'name'],
        'nom > 100' => [['name' => str_repeat('a', 101)], 'name'],
        'famille inexistante' => [['family_id' => 999999], 'family_id'],
        'famille non entière' => [['family_id' => 'Sagesse'], 'family_id'],
        'actif non booléen' => [['active' => 'oui'], 'active'],
    ]);

    it('exige family_id explicitement (null pour un destinataire global)', function (): void {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/report-recipients', ['name' => 'Pasteur', 'email' => 'pasteur@exemple.test'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['family_id']);
    });
});

describe('PATCH /api/admin/report-recipients/{id}', function (): void {
    it('modifie les champs fournis et journalise les champs modifiés', function (): void {
        $recipient = ReportRecipient::factory()->forFamily($this->sagesse)->create(['name' => 'Ancien', 'email' => 'ancien@exemple.test']);

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/report-recipients/{$recipient->id}", ['name' => 'Nouveau', 'active' => false, 'family_id' => null])
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $recipient->id,
                'family' => null,
                'name' => 'Nouveau',
                'email' => 'ancien@exemple.test',
                'active' => false,
            ]]);

        $log = AuditLog::query()->where('action', 'recipient.updated')->sole();

        expect($log->subject_id)->toBe($recipient->id)
            ->and($log->meta['fields'])->toEqualCanonicalizing(['family_id', 'name', 'active']);
    });

    it('ne journalise rien si aucune valeur ne change', function (): void {
        $recipient = ReportRecipient::factory()->create(['name' => 'Pasteur', 'email' => 'pasteur@exemple.test']);

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/report-recipients/{$recipient->id}", ['name' => 'Pasteur', 'email' => 'PASTEUR@exemple.test'])
            ->assertOk();

        expect(AuditLog::query()->where('action', 'recipient.updated')->exists())->toBeFalse();
    });

    it('accepte de conserver sa propre adresse', function (): void {
        $recipient = ReportRecipient::factory()->create(['email' => 'pasteur@exemple.test']);

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/report-recipients/{$recipient->id}", ['email' => 'pasteur@exemple.test', 'family_id' => null])
            ->assertOk();
    });

    it('refuse de créer un doublon en changeant seulement la famille', function (): void {
        ReportRecipient::factory()->create(['email' => 'pasteur@exemple.test']);
        $recipient = ReportRecipient::factory()->forFamily($this->sagesse)->create(['email' => 'pasteur@exemple.test']);

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/report-recipients/{$recipient->id}", ['family_id' => null])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email', ['Cette adresse reçoit déjà ces rapports.']);

        expect($recipient->refresh()->family_id)->toBe($this->sagesse->id);
    });

    it('refuse de créer un doublon en changeant seulement l\'adresse', function (): void {
        ReportRecipient::factory()->create(['email' => 'pasteur@exemple.test']);
        $recipient = ReportRecipient::factory()->create(['email' => 'autre@exemple.test']);

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/report-recipients/{$recipient->id}", ['email' => 'pasteur@exemple.test'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    });

    it('valide les champs fournis', function (): void {
        $recipient = ReportRecipient::factory()->create();

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/report-recipients/{$recipient->id}", ['email' => 'invalide', 'name' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'name']);
    });

    it('répond 404 pour un identifiant inconnu ou invalide', function (string $id): void {
        $this->actingAs($this->admin)
            ->patchJson("/api/admin/report-recipients/{$id}", ['name' => 'X'])
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    })->with(['999999', 'abc']);
});

describe('DELETE /api/admin/report-recipients/{id}', function (): void {
    it('supprime (204) et journalise', function (): void {
        $recipient = ReportRecipient::factory()->forFamily($this->force)->create();

        $this->actingAs($this->admin)
            ->deleteJson("/api/admin/report-recipients/{$recipient->id}")
            ->assertNoContent();

        expect(ReportRecipient::query()->count())->toBe(0);

        $log = AuditLog::query()->where('action', 'recipient.deleted')->sole();

        expect($log->subject_id)->toBe($recipient->id)
            ->and($log->meta)->toBe(['family_id' => $this->force->id]);
    });

    it('répond 404 pour un identifiant inconnu', function (): void {
        $this->actingAs($this->admin)->deleteJson('/api/admin/report-recipients/999999')->assertNotFound();
    });
});

describe('POST /api/admin/report-recipients/test', function (): void {
    it('envoie un e-mail de test à l\'adresse fournie (204)', function (): void {
        Mail::fake();

        $this->actingAs($this->admin)
            ->postJson('/api/admin/report-recipients/test', ['email' => 'Verification@Exemple.test'])
            ->assertNoContent();

        Mail::assertSent(TestMail::class, 1);
        Mail::assertSent(TestMail::class, fn (TestMail $mail): bool => count($mail->to) === 1
            && $mail->hasTo('verification@exemple.test')
            && $mail->requestedBy === 'Jean Kouassi');
    });

    it('envoie par défaut à l\'adresse de l\'utilisateur connecté', function (?string $email): void {
        Mail::fake();

        $this->actingAs($this->admin)
            ->postJson('/api/admin/report-recipients/test', $email === null ? [] : ['email' => $email])
            ->assertNoContent();

        Mail::assertSent(TestMail::class, fn (TestMail $mail): bool => count($mail->to) === 1 && $mail->hasTo('jean.kouassi@exemple.test'));
    })->with(['sans champ' => [null], 'champ vide' => ['']]);

    it('refuse une adresse invalide (422)', function (): void {
        Mail::fake();

        $this->actingAs($this->admin)
            ->postJson('/api/admin/report-recipients/test', ['email' => 'pas-une-adresse'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        Mail::assertNothingSent();
    });

    it('répond 503 mail_failed si le transport échoue, sans détail technique', function (): void {
        $transport = ReportData::useFailingMailer();

        $response = $this->actingAs($this->admin)
            ->postJson('/api/admin/report-recipients/test')
            ->assertStatus(503)
            ->assertExactJson(['message' => ReportDispatcher::MAIL_FAILED_MESSAGE, 'code' => 'mail_failed']);

        expect($transport->attempts)->toBe(1)
            ->and($response->getContent())->not->toContain('Brevo');
    });
});

describe('permissions', function (): void {
    it('réserve la gestion des destinataires au super_admin', function (Role $role): void {
        Mail::fake();
        $user = userWithRole($role);
        $recipient = ReportRecipient::factory()->create();

        $this->actingAs($user)->getJson('/api/admin/report-recipients')->assertForbidden();
        $this->actingAs($user)->postJson('/api/admin/report-recipients', ['family_id' => null, 'name' => 'X', 'email' => 'x@exemple.test'])->assertForbidden();
        $this->actingAs($user)->patchJson("/api/admin/report-recipients/{$recipient->id}", ['name' => 'Y'])->assertForbidden();
        $this->actingAs($user)->deleteJson("/api/admin/report-recipients/{$recipient->id}")->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->actingAs($user)->postJson('/api/admin/report-recipients/test')->assertForbidden();

        Mail::assertNothingSent();
        expect(ReportRecipient::query()->count())->toBe(1)
            ->and($recipient->refresh()->name)->not->toBe('Y');
    })->with([Role::Lecteur, Role::Moderateur]);

    it('refuse un invité (401)', function (): void {
        $this->getJson('/api/admin/report-recipients')->assertUnauthorized();
        $this->postJson('/api/admin/report-recipients/test')->assertUnauthorized();
    });
});

describe('e-mail de test : limite et traçabilité (revue de sécurité)', function (): void {
    it('journalise chaque envoi de test avec son destinataire', function (): void {
        Mail::fake();

        $this->actingAs($this->admin)
            ->postJson('/api/admin/report-recipients/test', ['email' => 'Verification@Exemple.test'])
            ->assertNoContent();

        $log = AuditLog::query()->where('action', 'report.test_sent')->sole();

        expect($log->user_id)->toBe($this->admin->id)
            ->and($log->subject_type)->toBeNull()
            ->and($log->meta)->toBe(['email' => 'verification@exemple.test']);
    });

    it('limite les e-mails de test à 5 par heure et par utilisateur', function (): void {
        Mail::fake();

        foreach (range(1, RateLimits::REPORT_TEST_PER_HOUR) as $n) {
            $this->actingAs($this->admin)
                ->postJson('/api/admin/report-recipients/test', ['email' => "cible{$n}@exemple.test"])
                ->assertNoContent();
        }

        $this->actingAs($this->admin)
            ->postJson('/api/admin/report-recipients/test', ['email' => 'cible6@exemple.test'])
            ->assertTooManyRequests()
            ->assertJsonPath('code', 'too_many_requests')
            ->assertHeader('Retry-After');

        Mail::assertSent(TestMail::class, RateLimits::REPORT_TEST_PER_HOUR);
        expect(AuditLog::query()->where('action', 'report.test_sent')->count())->toBe(RateLimits::REPORT_TEST_PER_HOUR);

        // Un autre administrateur garde son propre quota, et l'heure écoulée le rétablit.
        $other = userWithRole(Role::SuperAdmin, ['email' => 'autre@exemple.test']);
        $this->actingAs($other)->postJson('/api/admin/report-recipients/test')->assertNoContent();

        $this->travel(61)->minutes();
        $this->actingAs($this->admin)->postJson('/api/admin/report-recipients/test')->assertNoContent();
    });

    it('ne limite pas les autres routes « destinataires »', function (): void {
        Mail::fake();

        foreach (range(1, RateLimits::REPORT_TEST_PER_HOUR + 3) as $n) {
            $this->actingAs($this->admin)
                ->postJson('/api/admin/report-recipients', ['family_id' => null, 'name' => "N{$n}", 'email' => "n{$n}@exemple.test"])
                ->assertCreated();
        }
    });
});
