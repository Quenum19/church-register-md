<?php

namespace App\Notifications\Auth;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Lien de réinitialisation du mot de passe (broker « users », 60 min).
 *
 * ShouldBeEncrypted : la notification est mise en file (table `jobs`) avec le jeton en clair
 * dans sa charge utile sérialisée, et y resterait des jours en cas d'échec (`failed_jobs`).
 * Le jeton n'est donc écrit en base que chiffré (APP_KEY).
 */
class ResetPasswordNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(#[\SensitiveParameter] public readonly string $token)
    {
        $this->afterCommit();
    }

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
        $minutes = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Réinitialisation de votre mot de passe')
            ->greeting('Bonjour,')
            ->line("Une réinitialisation du mot de passe de votre compte d'administration du registre des visiteurs ({$church}) a été demandée.")
            ->action('Choisir un nouveau mot de passe', PasswordLinks::reset($this->token, $notifiable->email))
            ->line("Ce lien est valable {$minutes} minutes et ne peut servir qu'une seule fois.")
            ->line("Si vous n'êtes pas à l'origine de cette demande, ignorez cet e-mail : votre mot de passe actuel reste inchangé.")
            ->salutation($church);
    }
}
