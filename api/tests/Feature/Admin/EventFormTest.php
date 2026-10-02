<?php

use App\Enums\Source;
use App\Exports\EventFormExport;
use App\Exports\ExportLogo;
use App\Models\Event;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\Admin\Support\AdminFixtures;

/*
| Fiche de présence papier d'un événement — GET /api/admin/events/{event}/formulaire.pdf.
| Deuxième voie d'enregistrement à côté du QR code : les champs reprennent le formulaire allégé
| du lien événement, la fiche est vierge (aucune donnée personnelle) et se télécharge avec le
| cookie de session, comme les exports de visiteurs.
*/

beforeEach(function (): void {
    $this->travelTo(AdminFixtures::now());
    app(SettingsService::class)->set('public_url', 'https://registre.newinechurch.org');

    $this->event = Event::factory()->create([
        'name' => 'Culte spécial de la moisson',
        'slug' => 'culte-4-octobre',
        'event_date' => '2026-10-04',
    ]);

    $this->actingAs(User::factory()->superAdmin()->create());
});

/** Texte de chaque page d'un PDF dompdf (voir ExportsTest pour le détail du format). */
function eventFormPages(string $pdf): array
{
    preg_match_all('/\/Type \/Page[^s].{0,400}?\/Contents (\d+) 0 R/s', $pdf, $references);
    $pages = [];

    foreach ($references[1] as $id) {
        if (preg_match('/(?:^|\n)'.$id.' 0 obj\b.{0,400}?stream\r?\n(.*?)endstream/s', $pdf, $object) !== 1) {
            continue;
        }

        $content = @gzuncompress($object[1]);

        if ($content === false) {
            continue;
        }

        preg_match_all('/\[\((.*?)\)\]\s*TJ/s', $content, $runs);
        $pages[] = implode("\n", array_map(
            static fn (string $run): string => (string) mb_convert_encoding(stripcslashes($run), 'UTF-8', 'UTF-16BE'),
            $runs[1],
        ));
    }

    return $pages;
}

describe('endpoint', function (): void {
    it('télécharge un PDF A4 portrait nommé fiche-presence-{slug}.pdf', function (): void {
        $response = $this->get("/api/admin/events/{$this->event->id}/formulaire.pdf")->assertOk();

        expect($response->headers->get('Content-Type'))->toBe('application/pdf')
            ->and($response->headers->get('Content-Disposition'))
            ->toContain('attachment')->toContain('fiche-presence-culte-4-octobre.pdf')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and(substr((string) $response->getContent(), 0, 5))->toBe('%PDF-')
            // A4 portrait : 595,28 × 841,89 points.
            ->and((string) $response->getContent())->toMatch('/MediaBox \[0\.0+ 0\.0+ 595\.2\d* 841\.8\d*\]/');
    });

    it('répond 404 pour un événement inconnu ou un identifiant non numérique', function (string $id): void {
        $this->getJson("/api/admin/events/{$id}/formulaire.pdf")
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    })->with(['inconnu' => '999999', 'non numérique' => 'culte-4-octobre', 'vide' => 'x']);

    it('refuse 403 un compte qui n\'a pas visitors.view', function (): void {
        // Les trois rôles du contrat ont tous `visitors.view` : on neutralise la Gate pour
        // vérifier que la route est bien gardée par cette ability et non ouverte à tous.
        Gate::define('visitors.view', static fn (): bool => false);

        $this->getJson("/api/admin/events/{$this->event->id}/formulaire.pdf")
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    });

    it('ignore un paramètre de pagination hérité : la fiche reste unique', function (): void {
        // L'ancienne version acceptait « par_page » (1 ou 2 fiches). Un lien ou un signet
        // qui le porte encore ne doit ni échouer, ni ressortir deux fiches.
        $pages = eventFormPages((string) $this->get("/api/admin/events/{$this->event->id}/formulaire.pdf?par_page=2")->getContent());

        expect($pages)->toHaveCount(1)
            ->and(substr_count($pages[0], EventFormExport::CONSENT_START))->toBe(1);
    });
});

describe('contenu', function (): void {
    it('imprime une seule fiche par page A4, sans trait de coupe', function (): void {
        $pages = eventFormPages((string) $this->get("/api/admin/events/{$this->event->id}/formulaire.pdf")->getContent());

        expect($pages)->toHaveCount(1)
            ->and(substr_count($pages[0], EventFormExport::TITLE))->toBe(1)
            ->and(substr_count($pages[0], 'Nom et prénoms'))->toBe(1)
            ->and(substr_count($pages[0], EventFormExport::CONSENT_START))->toBe(1)
            ->and($pages[0])->not->toContain('découper ici');
    });

    it('porte le nom de l\'Église, le nom de l\'événement et sa date en toutes lettres', function (): void {
        app(SettingsService::class)->set('church_name', 'Église de la Grâce');

        $html = app(EventFormExport::class)->html($this->event, 1);

        // Blade échappe les apostrophes (&#039;) : on compare au texte tel qu'il est écrit.
        expect($html)->toContain('Église de la Grâce')
            ->toContain('Culte spécial de la moisson')
            ->toContain('dimanche 4 octobre 2026')
            ->toContain(e(EventFormExport::TITLE));
    });

    it('écrit « 1er » pour le premier du mois', function (): void {
        $this->event->update(['event_date' => '2026-11-01']);

        expect(app(EventFormExport::class)->html($this->event, 1))->toContain('dimanche 1er novembre 2026');
    });

    it('omet la ligne de date pour un événement sans date', function (): void {
        $undated = Event::factory()->withoutDate()->create(['name' => 'Évangélisation', 'slug' => 'evangelisation']);

        $html = app(EventFormExport::class)->html($undated);

        expect($html)->toContain('Évangélisation')
            ->not->toContain('event-date">');

        $this->get("/api/admin/events/{$undated->id}/formulaire.pdf")->assertOk();
    });

    it('reprend les champs du formulaire allégé de l\'événement', function (): void {
        $html = app(EventFormExport::class)->html($this->event, 2);

        expect($html)
            ->toContain('Nom et prénoms')
            ->toContain('Téléphone')
            ->toContain('Commune')
            ->toContain('Quartier')
            ->toContain(e(EventFormExport::SOURCE_QUESTION))
            ->toContain(Source::InviteMembre->label())
            ->toContain('Son nom')
            ->toContain(Source::Autre->label())
            ->toContain('Précisez')
            ->toContain(EventFormExport::WHATSAPP)
            ->toContain('Numéro WhatsApp, s\'il est différent du téléphone')
            ->toContain(e(EventFormExport::CONSENT))
            // Ni date, ni signature, ni mention de conservation : la case cochée fait foi,
            // comme à l'écran, et la fiche reste aérée.
            ->not->toContain('Signature')
            ->not->toContain('24 mois')
            ->not->toContain('conservées');

        // Champs écrits à la main sur des lignes, et non dans des cases à un chiffre :
        // une ligne par champ libre (nom, téléphone, commune, quartier, nom du membre,
        // précision, WhatsApp).
        expect($html)->not->toContain('class="digit"')
            ->and(substr_count($html, 'class="rule"'))->toBeGreaterThanOrEqual(7);
    });

    it('intègre le logo et le QR code du lien public en data URI, sans aucune URL distante', function (): void {
        expect(app(ExportLogo::class)->path())->toBeReadableFile();

        $html = app(EventFormExport::class)->html($this->event, 1);

        expect($html)->toContain('src="data:image/png;base64,')
            ->toContain('https://registre.newinechurch.org/e/culte-4-octobre')
            ->toContain(EventFormExport::QR_HINT)
            // Deux images par fiche : le logo et le QR code, toutes deux embarquées.
            ->and(substr_count($html, '<img'))->toBe(2)
            ->and($html)->not->toContain('src="http');

        $pdf = (string) $this->get("/api/admin/events/{$this->event->id}/formulaire.pdf")->getContent();

        // Deux objets image dans le document, et aucune ressource chargée par le réseau.
        expect(substr_count($pdf, '/Subtype /Image'))->toBeGreaterThanOrEqual(2)
            ->and($pdf)->not->toContain('http://');
    });

    it('échappe le nom d\'un événement contenant du HTML', function (): void {
        $hostile = Event::factory()->create([
            'name' => '<script>alert(1)</script>',
            'slug' => 'script',
            'event_date' => '2026-10-04',
        ]);

        $html = app(EventFormExport::class)->html($hostile);

        expect($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
            ->not->toContain('<script>')
            ->not->toContain('alert(1)</script>');

        // Le document se génère malgré tout.
        $this->get("/api/admin/events/{$hostile->id}/formulaire.pdf")->assertOk();
    });

    it('n\'utilise jamais la syntaxe Blade non échappée', function (): void {
        expect(file_get_contents(resource_path('views/exports/event-form.blade.php')))->not->toContain('{!!');
    });
});
