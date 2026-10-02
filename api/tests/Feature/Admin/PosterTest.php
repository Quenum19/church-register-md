<?php

use App\Exports\PosterExport;
use App\Models\Event;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\Admin\Support\AdminFixtures;

/*
| Affiches A5 du QR code d'inscription :
|   GET /api/admin/affiche.pdf                   — formulaire habituel
|   GET /api/admin/events/{event}/affiche.pdf    — culte spécial (lien dédié)
|
| Format des porte-affiches posés à l'accueil et sur les tables : une seule page A5 portrait,
| QR code de 74 mm, prête à imprimer. Mêmes garanties que les autres exports : aucune ressource
| distante, Blade échappé, téléchargement avec le cookie de session.
*/

beforeEach(function (): void {
    $this->travelTo(AdminFixtures::now());
    $this->settings = app(SettingsService::class);
    $this->settings->set('public_url', 'https://registre.newinechurch.org');

    $this->event = Event::factory()->create([
        'name' => 'Culte Spécial',
        'slug' => 'culte-special',
        'event_date' => '2026-10-04',
    ]);

    $this->actingAs(User::factory()->superAdmin()->create());
});

/** Nombre de pages d'un PDF dompdf : `/Type /Page` sans le `s` de l'arbre `/Type /Pages`. */
function posterPageCount(string $pdf): int
{
    return preg_match_all('/\/Type \/Page[^s]/', $pdf);
}

describe('affiche du formulaire habituel', function (): void {
    it('télécharge une seule page A5 portrait nommée affiche-qr-code.pdf', function (): void {
        $response = $this->get('/api/admin/affiche.pdf')->assertOk();
        $pdf = (string) $response->getContent();

        expect($response->headers->get('Content-Type'))->toBe('application/pdf')
            ->and($response->headers->get('Content-Disposition'))
            ->toContain('attachment')->toContain('affiche-qr-code.pdf')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and(substr($pdf, 0, 5))->toBe('%PDF-')
            // A5 portrait : 419,53 × 595,28 points.
            ->and($pdf)->toMatch('/MediaBox \[0\.0+ 0\.0+ 419\.5\d* 595\.2\d*\]/')
            ->and(posterPageCount($pdf))->toBe(1);
    });

    it('encode l\'adresse publique et annonce l\'enregistrement d\'une visite', function (): void {
        $export = app(PosterExport::class);

        expect($export->url())->toBe('https://registre.newinechurch.org')
            ->and($export->html())
            ->toContain('https://registre.newinechurch.org')
            ->toContain(PosterExport::VISIT_HEADLINE)
            ->toContain(PosterExport::VISIT_SUBTITLE)
            ->toContain(e(PosterExport::INSTRUCTION))
            // Ni nom d'événement ni date : c'est le formulaire de tous les dimanches.
            ->not->toContain('Culte Spécial');
    });
});

describe('affiche d\'un culte spécial', function (): void {
    it('télécharge une seule page A5 nommée affiche-{slug}.pdf', function (): void {
        $response = $this->get("/api/admin/events/{$this->event->id}/affiche.pdf")->assertOk();
        $pdf = (string) $response->getContent();

        expect($response->headers->get('Content-Disposition'))->toContain('affiche-culte-special.pdf')
            ->and($pdf)->toMatch('/MediaBox \[0\.0+ 0\.0+ 419\.5\d* 595\.2\d*\]/')
            ->and(posterPageCount($pdf))->toBe(1);
    });

    it('porte le nom du culte, sa date en toutes lettres et le lien dédié', function (): void {
        $export = app(PosterExport::class);

        expect($export->url($this->event))->toBe('https://registre.newinechurch.org/e/culte-special')
            ->and($export->html($this->event))
            ->toContain('Culte Spécial')
            ->toContain('Dimanche 4 octobre 2026')
            ->toContain(PosterExport::EVENT_HEADLINE)
            ->toContain('https://registre.newinechurch.org/e/culte-special');
    });

    it('écrit « 1er » pour le premier du mois', function (): void {
        $this->event->update(['event_date' => '2026-11-01']);

        expect(app(PosterExport::class)->html($this->event))->toContain('Dimanche 1er novembre 2026');
    });

    it('remplace la date absente par « Culte spécial »', function (): void {
        $undated = Event::factory()->withoutDate()->create(['name' => 'Évangélisation', 'slug' => 'evangelisation']);

        expect(app(PosterExport::class)->html($undated))
            ->toContain('Évangélisation')
            ->toContain(PosterExport::EVENT_SUBTITLE);

        $this->get("/api/admin/events/{$undated->id}/affiche.pdf")->assertOk();
    });
});

describe('contenu et sécurité', function (): void {
    it('reprend le nom de l\'Église et son verset', function (): void {
        $this->settings->set('church_name', 'Église de la Grâce');
        $this->settings->set('verse_text', 'Si tu m\'aimes, pais mes brebis.');
        $this->settings->set('verse_ref', 'Jean 21:17');

        expect(app(PosterExport::class)->html())
            ->toContain('Église de la Grâce')
            ->toContain(e('Si tu m\'aimes, pais mes brebis.'))
            ->toContain('Jean 21:17');
    });

    it('intègre le QR code en data URI, sans aucune ressource distante', function (): void {
        $html = app(PosterExport::class)->html($this->event);

        expect(substr_count($html, 'src="data:image/png;base64,'))->toBe(1)
            ->and($html)->not->toContain('src="http');

        $pdf = (string) $this->get("/api/admin/events/{$this->event->id}/affiche.pdf")->getContent();

        expect(substr_count($pdf, '/Subtype /Image'))->toBeGreaterThanOrEqual(1)
            ->and($pdf)->not->toContain('http://');
    });

    it('échappe le nom d\'un événement contenant du HTML', function (): void {
        $hostile = Event::factory()->create([
            'name' => '<script>alert(1)</script>',
            'slug' => 'script',
            'event_date' => '2026-10-04',
        ]);

        expect(app(PosterExport::class)->html($hostile))
            ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
            ->not->toContain('<script>a');

        $this->get("/api/admin/events/{$hostile->id}/affiche.pdf")->assertOk();
    });

    it('n\'utilise jamais la syntaxe Blade non échappée', function (): void {
        expect(file_get_contents(resource_path('views/exports/poster.blade.php')))->not->toContain('{!!');
    });
});

describe('accès', function (): void {
    it('répond 404 pour un événement inconnu ou un identifiant non numérique', function (string $id): void {
        $this->getJson("/api/admin/events/{$id}/affiche.pdf")
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    })->with(['inconnu' => '999999', 'non numérique' => 'culte-special']);

    it('refuse 403 un compte qui n\'a pas visitors.view', function (bool $forEvent): void {
        Gate::define('visitors.view', static fn (): bool => false);

        $path = $forEvent ? "/api/admin/events/{$this->event->id}/affiche.pdf" : '/api/admin/affiche.pdf';

        $this->getJson($path)->assertForbidden()->assertJsonPath('code', 'forbidden');
    })->with(['formulaire habituel' => false, 'culte spécial' => true]);
});
