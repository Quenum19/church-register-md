<?php

use App\Enums\Role;
use App\Mail\MonthlyReportMail;
use App\Mail\TestMail;
use App\Models\FamilyRotation;
use App\Models\ReportRecipient;
use App\Services\PhoneNumberService;
use App\Services\Reports\MonthlyReportService;
use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use Database\Seeders\FamilySeeder;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Tests\Feature\Reports\Support\ReportData;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-22 10:00', 'Africa/Abidjan'));
    $this->seed(FamilySeeder::class);
    config(['app.url' => 'https://registre.exemple.test']);

    ReportData::visitor(['2026-08-02'], ['full_name' => '<script>alert(1)</script>', 'phone' => '+2250700000001']);
    ReportData::visitor(['2026-07-05', '2026-08-09'], ['full_name' => 'Gisèle N\'Guessan & "Fils"', 'phone' => '+2250700000002']);
    $member = ReportData::visitor(['2026-01-04', '2026-02-01', '2026-03-01'], ['full_name' => '<img src=x onerror=alert(2)>']);
    ReportData::convert($member, '2026-08-20 11:00');

    app(SettingsService::class)->set('church_name', 'Église <b>La Maison</b> & Co');

    $this->mail = new MonthlyReportMail(app(MonthlyReportService::class)->forMonth(2026, 8), app(SettingsService::class)->churchName());
});

describe('MonthlyReportMail', function (): void {
    it('a pour sujet « Rapport des visiteurs — {mois année} — Famille {X} »', function (): void {
        expect($this->mail->envelope()->subject)->toBe('Rapport des visiteurs — août 2026 — Famille Sagesse');
    });

    it('échappe toute donnée dans le HTML (aucune injection)', function (): void {
        $html = $this->mail->render();

        expect($html)
            ->not->toContain('<script>alert(1)</script>')
            ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
            ->not->toContain('<img src=x onerror=alert(2)>')
            ->toContain('&lt;img src=x onerror=alert(2)&gt;')
            ->not->toContain('<b>La Maison</b>')
            ->toContain('Église &lt;b&gt;La Maison&lt;/b&gt; &amp; Co')
            ->toContain('Gisèle N&#039;Guessan &amp; &quot;Fils&quot;');
    });

    it('contient compteurs, visiteurs, conversions et lien vers le rapport', function (): void {
        $html = $this->mail->render();

        expect($html)
            ->toContain('Famille de service : <strong>Sagesse</strong>')
            ->toContain('>2</p>')   // total
            ->toContain(PhoneNumberService::formatInternational('+2250700000001'))
            ->toContain('1re visite')
            ->toContain('2e visite')
            ->toContain('02/08/2026')
            ->toContain('09/08/2026')
            ->toContain('20/08/2026')
            ->toContain('href="https://registre.exemple.test/admin/rapports/2026/8"');
    });

    it('utilise une mise en page en tableaux compatible Gmail et Outlook', function (): void {
        $html = $this->mail->render();

        expect($html)
            ->toContain('<table role="presentation"')
            ->toContain('<!--[if mso]>')
            ->not->toMatch('/display\s*:\s*(grid|flex)/i')
            ->not->toContain('<style')
            ->not->toContain('<link')
            ->not->toContain('<script');
    });

    it('fournit une version texte lisible (entités décodées)', function (): void {
        $this->mail->assertSeeInText('Gisèle N\'Guessan & "Fils"');
        $this->mail->assertSeeInText('Voir le rapport dans le registre : https://registre.exemple.test/admin/rapports/2026/8');
        $this->mail->assertSeeInText('Total : 2');
        $this->mail->assertSeeInText('Conversions : 1');
        $this->mail->assertSeeInText('Église <b>La Maison</b> & Co');
        $this->mail->assertDontSeeInText('&#039;');
        $this->mail->assertDontSeeInText('&amp;');
    });

    it('signale un rapport provisoire (mois en cours) et un mois sans famille', function (): void {
        $current = new MonthlyReportMail(app(MonthlyReportService::class)->forMonth(2026, 9), 'Église');
        $current->assertSeeInHtml('Mois en cours : ce rapport est provisoire');

        FamilyRotation::query()->where('year', 2026)->where('month', 7)->delete();
        $orphan = new MonthlyReportMail(app(MonthlyReportService::class)->forMonth(2026, 7), 'Église');

        expect($orphan->envelope()->subject)->toBe('Rapport des visiteurs — juillet 2026 — Famille non définie');
        $orphan->assertSeeInHtml("Aucune famille de service n'est définie pour ce mois", false);
    });

    it('part réellement par le mailer configuré avec les deux versions échappées', function (): void {
        ReportRecipient::factory()->forFamily(ReportData::family('Sagesse'))->create(['email' => 'sagesse@exemple.test']);
        ReportRecipient::factory()->create(['email' => 'pasteur@exemple.test']);

        // Transport « array » (phpunit.xml) : le message est réellement construit et rendu.
        $this->actingAs(userWithRole(Role::SuperAdmin))->postJson('/api/admin/reports/2026/8/send')->assertOk();

        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        expect($messages)->toHaveCount(1);

        /** @var Email $email */
        $email = $messages[0]->getOriginalMessage();

        $addresses = fn (array $list): array => array_map(fn ($address) => $address->getAddress(), $list);

        // Destinataires en copie cachée : aucune adresse de destinataire dans l'en-tête `To`.
        expect($addresses($email->getTo()))->toBe([(string) config('mail.from.address')])
            ->and($addresses($email->getBcc()))->toBe(['sagesse@exemple.test', 'pasteur@exemple.test'])
            ->and($email->getSubject())->toBe('Rapport des visiteurs — août 2026 — Famille Sagesse')
            ->and($email->getHtmlBody())->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
            ->and($email->getHtmlBody())->not->toContain('<script>')
            ->and($email->getTextBody())->toContain('Gisèle N\'Guessan & "Fils"')
            ->and($email->getTextBody())->toContain('https://registre.exemple.test/admin/rapports/2026/8');
    });
});

describe('TestMail', function (): void {
    it('vérifie la configuration avec le nom de l\'église (échappé)', function (): void {
        $mail = new TestMail(app(SettingsService::class)->churchName(), 'Jean <Admin>');

        expect($mail->envelope()->subject)->toBe('E-mail de test — Église <b>La Maison</b> & Co');

        $mail->assertSeeInHtml('Jean &lt;Admin&gt;', false);
        $mail->assertDontSeeInHtml('Jean <Admin>', false);
        $mail->assertSeeInText('Demandé par : Jean <Admin>');
    });
});

it('ne contient aucune adresse e-mail écrite en dur dans le code des rapports', function (): void {
    $paths = [
        app_path('Services/Reports'),
        app_path('Mail'),
        app_path('Http/Controllers/Reports'),
        app_path('Http/Requests/Reports'),
        app_path('Http/Resources/Reports'),
        app_path('Console/Commands/DispatchMonthlyReportCommand.php'),
        resource_path('views/mail/reports'),
        base_path('routes/api/reports.php'),
    ];

    $files = collect($paths)->flatMap(fn (string $path): array => is_dir($path)
        ? collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)))
            ->map(fn (SplFileInfo $file): string => $file->getPathname())
            ->values()
            ->all()
        : [$path]);

    expect($files->count())->toBeGreaterThan(10);

    foreach ($files as $file) {
        expect((string) file_get_contents($file))
            ->not->toMatch('/[A-Z0-9._%+-]+@[A-Z0-9-]+(\.[A-Z0-9-]+)*\.[A-Z]{2,}/i', "adresse en dur dans {$file}");
    }
});
