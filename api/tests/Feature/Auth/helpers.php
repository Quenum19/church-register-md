<?php

/*
|--------------------------------------------------------------------------
| Utilitaires des tests d'authentification (chargés par require_once)
|--------------------------------------------------------------------------
*/

use Database\Factories\UserFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/** En-tête rendant une requête « stateful » pour Sanctum (SANCTUM_STATEFUL_DOMAINS=localhost). */
const AUTH_SPA_HEADERS = ['Referer' => 'http://localhost/admin/connexion'];

/** Secret TOTP de test (celui de UserFactory::withTwoFactor()). */
const AUTH_TOTP_SECRET = 'JBSWY3DPEHPK3PXP';

/** Clés qui ne doivent JAMAIS apparaître dans une réponse de l'API. */
const AUTH_SECRET_KEYS = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'failed_attempts', 'locked_until'];

if (! function_exists('authAssertNoSecrets')) {
    /**
     * Vérifie qu'aucun champ secret (ni aucune valeur secrète fournie) n'apparaît dans la réponse.
     *
     * @param  list<string>  $secretValues
     */
    function authAssertNoSecrets(TestResponse $response, array $secretValues = []): void
    {
        $body = (string) $response->getContent();

        foreach (AUTH_SECRET_KEYS as $key) {
            expect($body)->not->toContain('"'.$key.'"');
        }

        foreach ($secretValues as $value) {
            expect($body)->not->toContain($value);
        }

        expect($body)->not->toContain('$argon2id$');
    }
}

if (! function_exists('authLogin')) {
    /**
     * POST /api/auth/login depuis le SPA (requête « stateful »).
     */
    function authLogin(string $email, string $password = UserFactory::PASSWORD, string $ip = '127.0.0.1'): TestResponse
    {
        return test()->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeaders(AUTH_SPA_HEADERS)
            ->postJson('/api/auth/login', ['email' => $email, 'password' => $password]);
    }
}

if (! function_exists('authChallenge')) {
    /**
     * POST /api/auth/two-factor/challenge depuis le SPA.
     *
     * @param  array<string, string>  $body
     */
    function authChallenge(array $body, string $ip = '127.0.0.1'): TestResponse
    {
        return test()->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeaders(AUTH_SPA_HEADERS)
            ->postJson('/api/auth/two-factor/challenge', $body);
    }
}

if (! function_exists('authTotp')) {
    /**
     * Code TOTP courant (décalé de `$periods` périodes de 30 s).
     */
    function authTotp(string $secret, int $periods = 0): string
    {
        $google2fa = new Google2FA;

        return $google2fa->oathTotp($secret, $google2fa->getTimestamp() + $periods);
    }
}

if (! function_exists('authEnableCsrf')) {
    /**
     * Laravel ignore la vérification CSRF pendant les tests : on la réactive pour de vrai.
     */
    function authEnableCsrf(): void
    {
        app()->bind(PreventRequestForgery::class, fn (Application $app) => new class($app, $app->make('encrypter')) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }
}

if (! function_exists('authResetState')) {
    /**
     * Simule un nouveau processus PHP entre deux requêtes : nouvelle instance de session et de gardes
     * (sinon l'état en mémoire d'une requête « fuit » vers la suivante dans les tests).
     */
    function authResetState(): void
    {
        app()->forgetInstance('session.store');
        app('session')->forgetDrivers();
        app('auth')->forgetGuards();
        app()->forgetInstance('auth.driver');
    }
}

if (! class_exists('AuthSpaClient')) {
    /**
     * Navigateur du dashboard : conserve son cookie de session (et XSRF-TOKEN) d'une requête à l'autre.
     * À utiliser avec SESSION_DRIVER=database (config(['session.driver' => 'database'])).
     */
    final class AuthSpaClient
    {
        public ?string $sessionId = null;

        public ?string $xsrfCookie = null;

        public function __construct(private readonly TestCase $test, public string $ip = '127.0.0.1') {}

        /**
         * @param  array<string, mixed>  $data
         * @param  array<string, string>  $headers
         */
        public function json(string $method, string $uri, array $data = [], array $headers = []): TestResponse
        {
            authResetState();

            $cookieName = (string) config('session.cookie');
            $cookies = [];

            if ($this->sessionId !== null) {
                $cookies[$cookieName] = encrypt(CookieValuePrefix::create($cookieName, app('encrypter')->getKey()).$this->sessionId, false);
            }

            $server = [
                'REMOTE_ADDR' => $this->ip,
                'HTTP_REFERER' => AUTH_SPA_HEADERS['Referer'],
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
            ];

            foreach ($headers as $name => $value) {
                $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
            }

            $content = $method === 'GET' ? null : (string) json_encode($data);
            $response = $this->test->call($method, $uri, [], $cookies, [], $server, $content);

            $session = $response->getCookie($cookieName);

            if ($session !== null) {
                $this->sessionId = $session->getValue();
            }

            $xsrf = $response->getCookie('XSRF-TOKEN', false);

            if ($xsrf !== null) {
                $this->xsrfCookie = $xsrf->getValue();
            }

            return $response;
        }
    }
}
