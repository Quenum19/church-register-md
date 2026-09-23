<?php

use App\Enums\Role;
use App\Exceptions\ApiException;
use App\Mail\MonthlyReportMail;
use App\Models\AuditLog;
use App\Models\FamilyRotation;
use App\Models\ReportDispatch;
use App\Models\ReportRecipient;
use App\Services\Reports\ReportDispatcher;
use Carbon\CarbonImmutable;
use Database\Seeders\FamilySeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Feature\Reports\Support\FailingTransport;
use Tests\Feature\Reports\Support\ReportData;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-22 10:00', 'Africa/Abidjan'));
    $this->seed(FamilySeeder::class);
    ReportData::scenario();
    $this->sagesse = ReportData::family('Sagesse');
    $this->admin = userWithRole(Role::SuperAdmin);
});

describe('envoi réussi', function (): void {
    beforeEach(function (): void {
        Mail::fake();
        ReportRecipient::factory()->forFamily($this->sagesse)->create(['email' => 'sagesse@exemple.test']);
        ReportRecipient::factory()->create(['email' => 'pasteur@exemple.test']);
    });

    it('envoie le rapport, enregistre l\'envoi et répond 200 { data: dispatch }', function (): void {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/reports/2026/8/send')
            ->assertOk()
            ->assertExactJson(['data' => [
                'sent_at' => '2026-09-22T10:00:00+00:00',
                'recipients' => ['sagesse@exemple.test', 'pasteur@exemple.test'],
            ]]);

        Mail::assertSent(MonthlyReportMail::class, 1);
        // Copie cachée : les destinataires ne se voient pas entre eux, `to` = adresse d'expédition.
        Mail::assertSent(MonthlyReportMail::class, fn (MonthlyReportMail $mail): bool => $mail->hasBcc('sagesse@exemple.test')
            && $mail->hasBcc('pasteur@exemple.test')
            && $mail->hasTo((string) config('mail.from.address'))
            && ! $mail->hasTo('sagesse@exemple.test')
            && ! $mail->hasTo('pasteur@exemple.test')
            && count($mail->to) === 1
            && $mail->report->year === 2026
            && $mail->report->month === 8
            && $mail->report->counts['total'] === 4);

        $dispatch = ReportDispatch::query()->sole();

        expect($dispatch->only(['year', 'month', 'family_id', 'sent_by', 'recipients']))->toBe([
            'year' => 2026,
            'month' => 8,
            'family_id' => $this->sagesse->id,
            'sent_by' => $this->admin->id,
            'recipients' => ['sagesse@exemple.test', 'pasteur@exemple.test'],
        ]);
    });

    it('journalise report.sent (envoi manuel)', function (): void {
        $this->actingAs($this->admin)->postJson('/api/admin/reports/2026/8/send')->assertOk();

        $log = AuditLog::query()->where('action', 'report.sent')->sole();
        $dispatch = ReportDispatch::query()->sole();

        expect($log->user_id)->toBe($this->admin->id)
            ->and($log->subject_type)->toBe('report')
            ->and($log->subject_id)->toBe($dispatch->id)
            ->and($log->meta)->toBe([
                'year' => 2026,
                'month' => 8,
                'family_id' => $this->sagesse->id,
                'recipients_count' => 2,
                'resent' => false,
                'automatic' => false,
            ]);
    });

    it('refuse un second envoi sans force (409 already_sent)', function (): void {
        $this->actingAs($this->admin)->postJson('/api/admin/reports/2026/8/send')->assertOk();

        $this->actingAs($this->admin)
            ->postJson('/api/admin/reports/2026/8/send', ['force' => false])
            ->assertStatus(409)
            ->assertJsonPath('code', 'already_sent');

        Mail::assertSent(MonthlyReportMail::class, 1);
        expect(ReportDispatch::query()->count())->toBe(1);
    });

    it('renvoie avec force et met à jour l\'envoi existant', function (): void {
        $first = ReportDispatch::factory()->forMonth(2026, 8)->create([
            'sent_at' => CarbonImmutable::parse('2026-09-01 08:00', 'Africa/Abidjan'),
            'recipients' => ['ancien@exemple.test'],
            'sent_by' => null,
        ]);

        $this->actingAs($this->admin)
            ->postJson('/api/admin/reports/2026/8/send', ['force' => true])
            ->assertOk()
            ->assertJsonPath('data.sent_at', '2026-09-22T10:00:00+00:00')
            ->assertJsonPath('data.recipients', ['sagesse@exemple.test', 'pasteur@exemple.test']);

        Mail::assertSent(MonthlyReportMail::class, 1);

        $dispatch = ReportDispatch::query()->sole();

        expect($dispatch->id)->toBe($first->id)
            ->and($dispatch->sent_by)->toBe($this->admin->id)
            ->and($dispatch->recipients)->toBe(['sagesse@exemple.test', 'pasteur@exemple.test'])
            ->and(AuditLog::query()->where('action', 'report.sent')->sole()->meta['resent'])->toBeTrue();
    });

    it('envoie aussi le rapport du mois en cours', function (): void {
        ReportRecipient::factory()->forFamily(ReportData::family('Force'))->create(['email' => 'force@exemple.test']);

        $this->actingAs($this->admin)
            ->postJson('/api/admin/reports/2026/9/send')
            ->assertOk()
            ->assertJsonPath('data.recipients', ['pasteur@exemple.test', 'force@exemple.test']);

        Mail::assertSent(MonthlyReportMail::class, fn (MonthlyReportMail $mail): bool => $mail->report->inProgress);
    });

    it('envoie aux seuls destinataires globaux un mois sans rotation', function (): void {
        FamilyRotation::query()->where('year', 2026)->where('month', 8)->delete();

        $this->actingAs($this->admin)
            ->postJson('/api/admin/reports/2026/8/send')
            ->assertOk()
            ->assertJsonPath('data.recipients', ['pasteur@exemple.test']);

        Mail::assertSent(MonthlyReportMail::class, fn (MonthlyReportMail $mail): bool => $mail->envelope()->subject === 'Rapport des visiteurs — août 2026 — Famille non définie');

        expect(ReportDispatch::query()->sole()->family_id)->toBeNull();
    });

    it('répond 404 pour un mois futur ou invalide, sans rien envoyer', function (string $path): void {
        $this->actingAs($this->admin)
            ->postJson("/api/admin/reports/{$path}/send")
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');

        Mail::assertNothingSent();
        expect(ReportDispatch::query()->count())->toBe(0);
    })->with(['2026/10', '2026/13', '2024/1']);

    it('valide force (booléen)', function (): void {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/reports/2026/8/send', ['force' => 'oui'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['force']);

        Mail::assertNothingSent();
    });
});

describe('destinataires', function (): void {
    beforeEach(function (): void {
        Mail::fake();
    });

    it('réunit la famille du mois et les globaux actifs, sans doublon ni inactif', function (): void {
        $force = ReportData::family('Force');

        ReportRecipient::factory()->forFamily($this->sagesse)->create(['email' => 'responsable@exemple.test']);
        ReportRecipient::factory()->forFamily($this->sagesse)->create(['email' => 'pasteur@exemple.test']);
        ReportRecipient::factory()->forFamily($this->sagesse)->inactive()->create(['email' => 'inactif-famille@exemple.test']);
        ReportRecipient::factory()->forFamily($force)->create(['email' => 'autre-famille@exemple.test']);
        ReportRecipient::factory()->create(['email' => 'Pasteur@Exemple.test']); // doublon (casse différente)
        ReportRecipient::factory()->create(['email' => 'secretariat@exemple.test']);
        ReportRecipient::factory()->inactive()->create(['email' => 'inactif-global@exemple.test']);

        expect(app(ReportDispatcher::class)->recipientsFor($this->sagesse->id))
            ->toBe(['responsable@exemple.test', 'pasteur@exemple.test', 'secretariat@exemple.test']);

        $this->actingAs($this->admin)
            ->postJson('/api/admin/reports/2026/8/send')
            ->assertOk()
            ->assertJsonPath('data.recipients', ['responsable@exemple.test', 'pasteur@exemple.test', 'secretariat@exemple.test']);

        Mail::assertSent(MonthlyReportMail::class, function (MonthlyReportMail $mail): bool {
            return count($mail->bcc) === 3
                && count($mail->to) === 1
                && ! $mail->hasBcc('inactif-famille@exemple.test')
                && ! $mail->hasBcc('inactif-global@exemple.test')
                && ! $mail->hasBcc('autre-famille@exemple.test');
        });
    });

    it('envoie à une famille sans destinataire dédié via les globaux', function (): void {
        ReportRecipient::factory()->create(['email' => 'pasteur@exemple.test']);

        $this->actingAs($this->admin)
            ->postJson('/api/admin/reports/2026/8/send')
            ->assertOk()
            ->assertJsonPath('data.recipients', ['pasteur@exemple.test']);
    });

    it('refuse l\'envoi sans destinataire actif (422 no_recipients)', function (): void {
        ReportRecipient::factory()->inactive()->create();
        ReportRecipient::factory()->forFamily(ReportData::family('Force'))->create();

        $this->actingAs($this->admin)
            ->postJson('/api/admin/reports/2026/8/send')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'no_recipients');

        Mail::assertNothingSent();
        expect(ReportDispatch::query()->count())->toBe(0)
            ->and(AuditLog::query()->where('action', 'report.sent')->exists())->toBeFalse();
    });
});

describe('échec du transport', function (): void {
    it('répond 503 mail_failed, journalise le détail et n\'écrit aucun envoi', function (): void {
        ReportRecipient::factory()->create(['email' => 'pasteur@exemple.test']);
        $transport = ReportData::useFailingMailer();
        Log::spy();

        $response = $this->actingAs($this->admin)
            ->postJson('/api/admin/reports/2026/8/send')
            ->assertStatus(503)
            ->assertExactJson(['message' => ReportDispatcher::MAIL_FAILED_MESSAGE, 'code' => 'mail_failed']);

        expect($transport->attempts)->toBe(1)
            ->and($response->getContent())->not->toContain('detail-technique-secret')
            ->and(ReportDispatch::query()->count())->toBe(0)
            ->and(AuditLog::query()->where('action', 'report.sent')->exists())->toBeFalse();

        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message, array $context): bool => $context['exception'] instanceof TransportException
                && $context['exception']->getMessage() === FailingTransport::DETAIL
                && $context['report'] === '2026-08',
        );
    });

    it('permet un nouvel essai une fois le transport rétabli', function (): void {
        ReportRecipient::factory()->create(['email' => 'pasteur@exemple.test']);
        ReportData::useFailingMailer();

        $this->actingAs($this->admin)->postJson('/api/admin/reports/2026/8/send')->assertStatus(503);

        Mail::fake();

        $this->actingAs($this->admin)->postJson('/api/admin/reports/2026/8/send')->assertOk();

        expect(ReportDispatch::query()->count())->toBe(1);
    });
});

describe('concurrence', function (): void {
    it('répond 409 send_in_progress si un envoi du même mois est déjà en cours', function (): void {
        Mail::fake();
        ReportRecipient::factory()->create();

        // Un autre processus (envoi automatique, double clic) détient le verrou du mois.
        $lock = Cache::lock('reports:dispatch:2026-8', ReportDispatcher::LOCK_SECONDS);
        expect($lock->get())->toBeTrue();

        // Délai d'attente nul : l'horloge est figée dans les tests (Lock::block() se base sur now()).
        $dispatcher = app()->make(ReportDispatcher::class, ['lockWaitSeconds' => 0]);

        expect(fn () => $dispatcher->send(2026, 8, $this->admin))
            ->toThrow(function (ApiException $e): void {
                expect($e->errorCode)->toBe('send_in_progress')
                    ->and($e->status)->toBe(409);
            });

        $lock->release();

        Mail::assertNothingSent();
        expect(ReportDispatch::query()->count())->toBe(0);

        // Verrou libéré : l'envoi suivant passe.
        expect($dispatcher->send(2026, 8, $this->admin)->recipients)->toHaveCount(1);
    });
});

describe('permissions', function (): void {
    it('réserve l\'envoi au super_admin', function (Role $role): void {
        Mail::fake();
        ReportRecipient::factory()->create();

        $this->actingAs(userWithRole($role))
            ->postJson('/api/admin/reports/2026/8/send')
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        Mail::assertNothingSent();
    })->with([Role::Lecteur, Role::Moderateur]);

    it('refuse un invité (401)', function (): void {
        $this->postJson('/api/admin/reports/2026/8/send')->assertUnauthorized();
    });
});
