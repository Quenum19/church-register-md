<?php

namespace App\Providers;

use App\Enums\Ability;
use App\Models\Family;
use App\Models\FamilyRotation;
use App\Models\Member;
use App\Models\ReportDispatch;
use App\Models\ReportRecipient;
use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitorNote;
use App\Services\AuditLogger;
use App\Services\FamilyRotationService;
use App\Services\SettingsService;
use App\Services\VisitorStatusService;
use App\Support\RateLimits;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoApiTransport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Morph map imposée (alias => modèle) : valeurs possibles de `subject_type` dans le journal d'audit.
     *
     * @var array<string, class-string<Model>>
     */
    public const MORPH_MAP = [
        'visitor' => Visitor::class,
        'visit' => Visit::class,
        'note' => VisitorNote::class,
        'member' => Member::class,
        'user' => User::class,
        'family' => Family::class,
        'rotation' => FamilyRotation::class,
        'recipient' => ReportRecipient::class,
        'report' => ReportDispatch::class,
    ];

    /**
     * Client HTTP de l'API Brevo : `timeout` = inactivité maximale (connexion, attente de la réponse),
     * `max_duration` = durée totale maximale d'un envoi, en secondes.
     *
     * @var array{timeout: int, max_duration: int}
     */
    public const BREVO_HTTP_OPTIONS = ['timeout' => 15, 'max_duration' => 30];

    public function register(): void
    {
        $this->app->singleton(SettingsService::class);
        $this->app->singleton(FamilyRotationService::class);
        $this->app->singleton(VisitorStatusService::class);
        $this->app->singleton(AuditLogger::class);
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configurePasswords();
        $this->configureGates();
        $this->configureMail();

        RateLimits::register();
    }

    private function configureModels(): void
    {
        // Alias courts du contrat (§4, journal d'audit), stockés tels quels dans audit_logs.subject_type
        // et exposés sans conversion par AuditLogResource.
        // Setting (clé primaire chaîne) et AuditLog ne sont jamais sujets d'une entrée du journal :
        // « settings.updated » n'a pas de sujet, les champs modifiés sont dans meta.fields.
        Relation::enforceMorphMap(self::MORPH_MAP);

        // Hors production : les requêtes N+1 et les attributs non chargés lèvent une exception.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventAccessingMissingAttributes(! $this->app->isProduction());

        // Interdit migrate:fresh, db:wipe… en production.
        DB::prohibitDestructiveCommands($this->app->isProduction());
    }

    /**
     * Politique de mot de passe du contrat : 12 caractères minimum, lettres et chiffres.
     * Utiliser Password::defaults() dans les Form Requests et la commande admin:create.
     */
    private function configurePasswords(): void
    {
        Password::defaults(static fn (): Password => Password::min(12)->letters()->numbers());
    }

    /**
     * Une Gate par ability du contrat (§1). Un compte désactivé n'a aucune permission.
     */
    private function configureGates(): void
    {
        foreach (Ability::cases() as $ability) {
            Gate::define(
                $ability->value,
                static fn (User $user): bool => $user->is_active && $user->role->allows($ability),
            );
        }
    }

    /**
     * Transport « brevo » : API HTTP Brevo (MAIL_MAILER=brevo, clé BREVO_API_KEY).
     *
     * Délais HTTP bornés : un Brevo muet ou lent fait échouer l'envoi (503 `mail_failed` pour les
     * rapports, nouvel essai de la file pour les notifications) au lieu de bloquer la requête
     * jusqu'à max_execution_time.
     */
    private function configureMail(): void
    {
        Mail::extend('brevo', static function (): BrevoApiTransport {
            $key = config('services.brevo.key');

            if (! is_string($key) || $key === '') {
                throw new InvalidArgumentException("BREVO_API_KEY n'est pas définie.");
            }

            return new BrevoApiTransport($key, HttpClient::create(self::BREVO_HTTP_OPTIONS));
        });
    }
}
