<?php

use App\Enums\Role;
use App\Mail\MonthlyReportMail;
use App\Models\AuditLog;
use App\Models\ReportDispatch;
use App\Models\ReportRecipient;
use Database\Seeders\FamilySeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Reports\Support\ReportData;

beforeEach(function (): void {
    $this->travelTo(ReportData::at('2026-10-01 08:00'));
    $this->seed(FamilySeeder::class);
    ReportData::scenario();
    $this->force = ReportData::family('Force');
});

describe('envoi automatique', function (): void {
    beforeEach(function (): void {
        Mail::fake();
        ReportRecipient::factory()->forFamily($this->force)->create(['email' => 'force@exemple.test']);
        ReportRecipient::factory()->create(['email' => 'pasteur@exemple.test']);
    });

    it('envoie le rapport du mois précédent au premier passage', function (): void {
        $this->artisan('reports:dispatch')
            ->expectsOutputToContain('Rapport de 2026-09 envoyé à 2 destinataire(s).')
            ->assertSuccessful();

        Mail::assertSent(MonthlyReportMail::class, fn (MonthlyReportMail $mail): bool => $mail->report->year === 2026
            && $mail->report->month === 9
            && $mail->report->family?->name === 'Force'
            && $mail->hasBcc('force@exemple.test')
            && $mail->hasBcc('pasteur@exemple.test')
            && $mail->hasTo((string) config('mail.from.address')));

        $dispatch = ReportDispatch::query()->sole();

        expect($dispatch->only(['year', 'month', 'family_id', 'sent_by', 'recipients']))->toBe([
            'year' => 2026,
            'month' => 9,
            'family_id' => $this->force->id,
            'sent_by' => null,
            'recipients' => ['force@exemple.test', 'pasteur@exemple.test'],
        ]);

        $log = AuditLog::query()->where('action', 'report.sent')->sole();

        expect($log->user_id)->toBeNull()
            ->and($log->subject_id)->toBe($dispatch->id)
            ->and($log->meta['automatic'])->toBeTrue()
            ->and($log->meta['resent'])->toBeFalse();
    });

    it('n\'envoie rien au second passage (idempotente)', function (): void {
        $this->artisan('reports:dispatch')->assertSuccessful();

        $this->travelTo(ReportData::at('2026-10-02 08:00'));

        $this->artisan('reports:dispatch')
            ->expectsOutputToContain('Rapport de 2026-09 déjà envoyé le 01/10/2026 à 08:00')
            ->assertSuccessful();

        Mail::assertSent(MonthlyReportMail::class, 1);
        expect(ReportDispatch::query()->count())->toBe(1)
            ->and(AuditLog::query()->where('action', 'report.sent')->count())->toBe(1);
    });

    it('rattrape l\'envoi le 5 du mois si le serveur était arrêté le 1er', function (): void {
        $this->travelTo(ReportData::at('2026-10-05 08:00'));

        $this->artisan('reports:dispatch')->assertSuccessful();

        Mail::assertSent(MonthlyReportMail::class, fn (MonthlyReportMail $mail): bool => $mail->report->month === 9);
        expect(ReportDispatch::query()->sole()->sent_at->toDateString())->toBe('2026-10-05');
    });

    it('n\'envoie rien si le rapport a déjà été envoyé manuellement', function (): void {
        ReportDispatch::factory()->forMonth(2026, 9)->create(['sent_by' => userWithRole(Role::SuperAdmin)->id]);

        $this->artisan('reports:dispatch')->assertSuccessful();

        Mail::assertNothingSent();
    });

    it('envoie le mois demandé avec --month', function (): void {
        $this->artisan('reports:dispatch', ['--month' => '2026-08'])
            ->expectsOutputToContain('Rapport de 2026-08 envoyé')
            ->assertSuccessful();

        Mail::assertSent(MonthlyReportMail::class, fn (MonthlyReportMail $mail): bool => $mail->report->month === 8
            && $mail->report->family?->name === 'Sagesse'
            && $mail->hasBcc('pasteur@exemple.test')
            && ! $mail->hasBcc('force@exemple.test'));

        expect(ReportDispatch::query()->forMonth(2026, 8)->sole()->sent_by)->toBeNull();

        // Relancée : rien de plus.
        $this->artisan('reports:dispatch', ['--month' => '2026-08'])->assertSuccessful();
        Mail::assertSent(MonthlyReportMail::class, 1);
    });

    it('refuse un --month invalide, en cours ou futur', function (string $month): void {
        $this->artisan('reports:dispatch', ['--month' => $month])
            ->expectsOutputToContain('Option --month invalide')
            ->assertExitCode(Command::INVALID);

        Mail::assertNothingSent();
    })->with(['2026-10', '2026-11', '2026-13', '2026-9', 'septembre', '2026/09']);

    it('ignore un mois antérieur à la première visite', function (): void {
        $this->artisan('reports:dispatch', ['--month' => '2024-05'])
            ->expectsOutputToContain('Aucun rapport pour 2024-05')
            ->assertSuccessful();

        Mail::assertNothingSent();
        expect(ReportDispatch::query()->count())->toBe(0);
    });
});

describe('cas d\'échec', function (): void {
    it('sans destinataire : avertissement journalisé, code 0, aucun envoi enregistré', function (): void {
        Mail::fake();
        Log::spy();
        ReportRecipient::factory()->forFamily(ReportData::family('Sagesse'))->create();

        $this->artisan('reports:dispatch')
            ->expectsOutputToContain('aucun destinataire actif')
            ->assertSuccessful();

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => str_contains($message, 'aucun destinataire')
                && $context === ['year' => 2026, 'month' => 9],
        );
        Mail::assertNothingSent();
        expect(ReportDispatch::query()->count())->toBe(0);
    });

    it('échec Brevo le 1er : code d\'erreur, rien d\'enregistré, puis envoi le lendemain', function (): void {
        ReportRecipient::factory()->create(['email' => 'pasteur@exemple.test']);
        $transport = ReportData::useFailingMailer();

        $this->artisan('reports:dispatch')
            ->expectsOutputToContain("Échec de l'envoi du rapport de 2026-09")
            ->assertFailed();

        expect($transport->attempts)->toBe(1)
            ->and(ReportDispatch::query()->count())->toBe(0);

        // Le lendemain, le transport est rétabli : rattrapage automatique.
        $this->travelTo(ReportData::at('2026-10-02 08:00'));
        Mail::fake();

        $this->artisan('reports:dispatch')->assertSuccessful();

        Mail::assertSent(MonthlyReportMail::class, 1);
        expect(ReportDispatch::query()->sole()->sent_at->toDateString())->toBe('2026-10-02');
    });
});

describe('planification', function (): void {
    it('est planifiée chaque jour à 08:00 (Africa/Abidjan), sans chevauchement', function (): void {
        $this->artisan('schedule:list')->expectsOutputToContain('reports:dispatch')->assertSuccessful();

        $event = ReportData::scheduledEvent('reports:dispatch');

        expect($event->expression)->toBe('0 8 * * *')
            ->and((string) $event->timezone)->toBe('Africa/Abidjan')
            ->and($event->withoutOverlapping)->toBeTrue()
            // Verrou court : un verrou orphelin ne bloque pas l'exécution du lendemain.
            ->and($event->expiresAt)->toBeLessThan(24 * 60);
    });

    it('est due à 08:00 heure d\'Abidjan chaque jour, pas à une autre heure', function (string $datetime, bool $due): void {
        $this->travelTo(ReportData::at($datetime));

        expect(ReportData::scheduledEvent('reports:dispatch')->isDue(app()))->toBe($due);
    })->with([
        'le 1er à 08:00' => ['2026-10-01 08:00', true],
        'le 2 à 08:00 (rattrapage)' => ['2026-10-02 08:00', true],
        'le 15 à 08:00' => ['2026-10-15 08:00', true],
        'le 1er à 07:59' => ['2026-10-01 07:59', false],
        'le 1er à 09:00' => ['2026-10-01 09:00', false],
    ]);
});
