<?php

namespace App\Notifications\Auth;

use App\Models\User;
use App\Services\SettingsService;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Compte verrouillé après trop d'échecs de connexion (voir App\Services\Auth\LoginThrottle).
 * Ne contient ni mot de passe ni adresse IP complète (seulement son début, ex. « 203.0.x.x »).
 * Envoyée au plus une fois par heure et par compte.
 */
class AccountLockedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly CarbonInterface $lockedUntil,
        public readonly ?string $maskedIp,
        /** true : compte verrouillé pour toutes les origines ; false : seule cette IP est bloquée. */
        public readonly bool $wholeAccount = false,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $church = app(SettingsService::class)->churchName();
        $until = $this->lockedUntil->copy()->setTimezone((string) config('app.timezone'))->format('d/m/Y à H:i');
        $origin = $this->maskedIp !== null ? " (adresse IP commençant par {$this->maskedIp})" : '';

        $scope = $this->wholeAccount
            ? "Par sécurité, le compte est verrouillé jusqu'au {$until} (heure d'Abidjan), puis se déverrouillera automatiquement."
            : "Par sécurité, les tentatives venant de cette adresse sont bloquées jusqu'au {$until} (heure d'Abidjan). "
                .'Vous pouvez continuer à vous connecter normalement depuis une autre connexion.';

        return (new MailMessage)
            ->subject('Votre compte a été temporairement verrouillé')
            ->greeting('Bonjour,')
            ->line("De nombreuses tentatives de connexion infructueuses ont visé votre compte d'administration du registre des visiteurs ({$church}){$origin}.")
            ->line($scope)
            ->line("Si ces tentatives ne viennent pas de vous, quelqu'un cherche peut-être à accéder à votre compte : choisissez un nouveau mot de passe et activez la double authentification.")
            ->action('Réinitialiser mon mot de passe', PasswordLinks::forgotPassword())
            ->salutation($church);
    }
}
