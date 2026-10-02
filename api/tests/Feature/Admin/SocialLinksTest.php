<?php

/*
|--------------------------------------------------------------------------
| Réseaux sociaux : paramètres, page publique et affichette à poser sur les tables.
|--------------------------------------------------------------------------
*/

use App\Exports\SocialPosterExport;
use App\Models\User;
use App\Services\Journey\PublicConfigService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Gate;

beforeEach(function (): void {
    $this->settings = app(SettingsService::class);
    $this->settings->set('public_url', 'https://registre.newinechurch.org');
    $this->actingAs(User::factory()->superAdmin()->create());
});

describe('paramètres', function (): void {
    it('enregistre les adresses et les renvoie avec les libellés', function (): void {
        $this->putJson('/api/admin/settings', [
            'social_links' => [
                'facebook' => 'https://facebook.com/newinechurch',
                'youtube' => 'https://youtube.com/@newinechurch',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('social_links.facebook', 'https://facebook.com/newinechurch')
            ->assertJsonPath('social_links.youtube', 'https://youtube.com/@newinechurch')
            ->assertJsonPath('social_links.instagram', null)
            ->assertJsonPath('social_networks.facebook', 'Facebook');
    });

    it('refuse une adresse qui n\'est pas une URL https', function (): void {
        $this->putJson('/api/admin/settings', ['social_links' => ['facebook' => 'facebook.com/eglise']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['social_links.facebook']);

        $this->putJson('/api/admin/settings', ['social_links' => ['youtube' => 'http://youtube.com/@eglise']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['social_links.youtube']);
    });

    it('efface un réseau avec une chaîne vide, sans toucher aux autres', function (): void {
        $this->settings->set('social_links', [
            'facebook' => 'https://facebook.com/eglise',
            'youtube' => 'https://youtube.com/@eglise',
        ]);

        $this->putJson('/api/admin/settings', ['social_links' => ['facebook' => '']])
            ->assertOk()
            ->assertJsonPath('social_links.facebook', null)
            ->assertJsonPath('social_links.youtube', 'https://youtube.com/@eglise');
    });

    it('journalise la modification et n\'accepte que settings.update', function (): void {
        $this->putJson('/api/admin/settings', ['social_links' => ['facebook' => 'https://facebook.com/eglise']])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.updated']);

        Gate::define('settings.update', static fn (): bool => false);

        $this->putJson('/api/admin/settings', ['social_links' => ['facebook' => 'https://facebook.com/x']])
            ->assertForbidden();
    });
});

describe('page publique', function (): void {
    it('expose les réseaux renseignés, sans les autres', function (): void {
        $this->settings->set('social_links', ['youtube' => 'https://youtube.com/@eglise']);
        app(PublicConfigService::class)->forget();

        $this->getJson('/api/public/config')
            ->assertOk()
            ->assertJsonPath('social_networks.0.key', 'youtube')
            ->assertJsonPath('social_networks.0.label', 'YouTube')
            ->assertJsonPath('social_networks.0.url', 'https://youtube.com/@eglise')
            ->assertJsonCount(1, 'social_networks');
    });

    it('renvoie une liste vide quand rien n\'est renseigné', function (): void {
        app(PublicConfigService::class)->forget();

        $this->getJson('/api/public/config')->assertOk()->assertJsonCount(0, 'social_networks');
    });
});

describe('affichette', function (): void {
    beforeEach(function (): void {
        $this->settings->set('social_links', [
            'facebook' => 'https://facebook.com/newinechurch',
            'youtube' => 'https://youtube.com/@newinechurch',
        ]);
    });

    it('télécharge un PDF A4 portrait de deux cartes identiques', function (): void {
        $response = $this->get('/api/admin/reseaux-sociaux/affiche.pdf')->assertOk();

        expect($response->headers->get('Content-Type'))->toBe('application/pdf')
            ->and($response->headers->get('Content-Disposition'))->toContain(SocialPosterExport::FILENAME)
            ->and(substr((string) $response->getContent(), 0, 5))->toBe('%PDF-')
            ->and((string) $response->getContent())->toMatch('/MediaBox \[0\.0+ 0\.0+ 595\.2\d* 841\.8\d*\]/');
    });

    it('encode la page publique des réseaux et cite les réseaux renseignés', function (): void {
        $export = app(SocialPosterExport::class);
        $html = $export->html();

        expect($export->url())->toBe('https://registre.newinechurch.org/reseaux')
            ->and($html)->toContain('https://registre.newinechurch.org/reseaux')
            ->toContain('Facebook · YouTube')
            ->toContain(SocialPosterExport::TITLE)
            // Logo et QR code embarqués, aucune ressource distante.
            ->and(substr_count($html, 'src="data:image/png;base64,'))->toBe(4)
            ->and($html)->not->toContain('src="http');
    });

    it('échappe un nom d\'église contenant du HTML', function (): void {
        $this->settings->set('church_name', '<script>alert(1)</script>');

        expect(app(SocialPosterExport::class)->html())
            ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
            ->not->toContain('<script>a');
    });

    it('refuse 403 un compte qui n\'a pas visitors.view', function (): void {
        Gate::define('visitors.view', static fn (): bool => false);

        $this->getJson('/api/admin/reseaux-sociaux/affiche.pdf')
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    });

    it('n\'utilise jamais la syntaxe Blade non échappée', function (): void {
        expect(file_get_contents(resource_path('views/exports/social-poster.blade.php')))->not->toContain('{!!');
    });
});
