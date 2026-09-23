<?php

use App\Models\Visitor;
use App\Providers\AppServiceProvider;
use App\Rules\PhoneNumber;
use App\Support\Pagination;
use App\Support\RouteGroups;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoApiTransport;
use Symfony\Component\Mailer\Transport\AbstractHttpTransport;

describe('règle PhoneNumber', function (): void {
    it('valide selon le pays fourni', function (?string $country, string $phone, bool $valid): void {
        $validator = Validator::make(['phone' => $phone], ['phone' => [new PhoneNumber($country)]]);

        expect($validator->passes())->toBe($valid);

        if (! $valid) {
            expect($validator->errors()->first('phone'))->toBe('Le numéro de téléphone est invalide.');
        }
    })->with([
        'CI par défaut' => [null, '07 00 00 00 00', true],
        'CI explicite' => ['CI', '0700000000', true],
        'FR' => ['FR', '06 12 34 56 78', true],
        'OTHER' => ['OTHER', '+44 7400 123456', true],
        'CI invalide' => ['CI', '07 00 00', false],
        'pays inconnu' => ['XX', '0700000000', false],
    ]);

    it('refuse une valeur non textuelle', function (): void {
        expect(Validator::make(['phone' => ['07']], ['phone' => [new PhoneNumber('CI')]])->fails())->toBeTrue();
    });
});

describe('Pagination', function (): void {
    beforeEach(function (): void {
        testRoute(RouteGroups::PUBLIC_PREFIX, RouteGroups::PUBLIC_MIDDLEWARE, 'GET', '__page', function (Request $request) {
            $request->validate(Pagination::rules());

            return Pagination::response(
                Visitor::query()->orderBy('id')->paginate(Pagination::perPage($request)),
                fn (Visitor $visitor): array => ['id' => $visitor->id],
            );
        });
    });

    it('produit le format du contrat', function (): void {
        Visitor::factory()->count(3)->create();

        $this->getJson('/api/public/__page?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure(['data' => [['id']], 'meta' => ['current_page', 'last_page', 'per_page', 'total']])
            ->assertJsonPath('meta', ['current_page' => 2, 'last_page' => 2, 'per_page' => 2, 'total' => 3])
            ->assertJsonMissingPath('links');
    });

    it('renvoie last_page = 1 pour une liste vide', function (): void {
        $this->getJson('/api/public/__page')
            ->assertOk()
            ->assertExactJson(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 0]]);
    });

    it('rejette les paramètres hors bornes (422)', function (string $query): void {
        $this->getJson("/api/public/__page?{$query}")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation');
    })->with(['per_page=0', 'per_page=101', 'page=0', 'page=abc']);
});

it('n\'expose aucune route en CORS', function (): void {
    $this->withHeaders(['Origin' => 'https://site-tiers.example', 'Access-Control-Request-Method' => 'GET'])
        ->options('/api/health')
        ->assertHeaderMissing('Access-Control-Allow-Origin');

    $this->withHeader('Origin', 'https://site-tiers.example')
        ->getJson('/api/health')
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});

it('pose le cookie XSRF-TOKEN via /sanctum/csrf-cookie', function (): void {
    $response = $this->get('/sanctum/csrf-cookie')->assertNoContent();

    $names = array_map(fn ($cookie) => $cookie->getName(), $response->headers->getCookies());

    expect($names)->toContain('XSRF-TOKEN');
});

it('fournit le transport e-mail « brevo » (API HTTP)', function (): void {
    config(['services.brevo.key' => 'cle-de-test']);

    expect(Mail::mailer('brevo')->getSymfonyTransport())
        ->toBeInstanceOf(BrevoApiTransport::class);
});

it('borne les délais HTTP du transport « brevo » (15 s d\'inactivité, 30 s au total)', function (): void {
    config(['services.brevo.key' => 'cle-de-test']);

    $transport = Mail::mailer('brevo')->getSymfonyTransport();
    $client = (new ReflectionProperty(AbstractHttpTransport::class, 'client'))->getValue($transport);
    expect($client)->toBeObject();

    // Options par défaut du client Symfony (CurlHttpClient ou NativeHttpClient selon l'hébergement).
    $options = (new ReflectionProperty($client, 'defaultOptions'))->getValue($client);

    expect(AppServiceProvider::BREVO_HTTP_OPTIONS)->toBe(['timeout' => 15, 'max_duration' => 30])
        ->and((float) $options['timeout'])->toBe(15.0)
        ->and((float) $options['max_duration'])->toBe(30.0);
});

it('n\'invente aucune adresse d\'expéditeur en production (config/mail.php)', function (?string $appEnv, ?string $expected): void {
    $names = ['APP_ENV', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME'];
    $backup = [];

    foreach ($names as $name) {
        $backup[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)];
        unset($_SERVER[$name], $_ENV[$name]);
        putenv($name);
    }

    if ($appEnv !== null) {
        $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = $appEnv;
        putenv("APP_ENV={$appEnv}");
    }

    try {
        $config = require config_path('mail.php');
    } finally {
        foreach ($backup as $name => [$server, $env, $put]) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);

            if ($server !== null) {
                $_SERVER[$name] = $server;
            }

            if ($env !== null) {
                $_ENV[$name] = $env;
            }

            if ($put !== false) {
                putenv("{$name}={$put}");
            }
        }
    }

    expect($config['from']['address'])->toBe($expected)
        ->and($config['from']['name'])->toBe('Église La Maison de la Destinée');
})->with([
    'production' => ['production', null],
    'APP_ENV absent (production par défaut)' => [null, null],
    'local' => ['local', 'registre@exemple.test'],
]);

it('refuse le transport « brevo » sans clé API', function (): void {
    config(['services.brevo.key' => null]);

    Mail::mailer('brevo');
})->throws(InvalidArgumentException::class, "BREVO_API_KEY n'est pas définie.");
