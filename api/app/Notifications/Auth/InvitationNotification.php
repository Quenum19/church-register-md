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
 * Invitation d'un administrateur : lien de définition du mot de passe (broker « invitations », 48 h).
 * Aucun mot de passe n'est jamais envoyé par e-mail.
 *
 * ShouldBeEncrypted : le jeton d'invitation (48 h) ne doit jamais dormir en clair dans la
 * table `jobs` ni dans `failed_jobs` (charge utile chiffrée avec APP_KEY).
 */
class InvitationNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
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
        $hours = intdiv((int) config('auth.passwords.invitations.expire', 48 * 60), 60);

        return (new MailMessage)
            ->subject('Invitation : accès au registre des visiteurs')
            ->greeting('Bonjour,')
            ->line("Un accès au tableau de bord du registre des visiteurs ({$church}) vient de vous être ouvert, avec le rôle « {$notifiable->role->label()} ».")
            ->line('Pour activer votre compte, choisissez votre mot de passe :')
            ->action('Choisir mon mot de passe', PasswordLinks::invitation($this->token, $notifiable->email))
            ->line("Ce lien est valable {$hours} heures et ne peut servir qu'une seule fois. Passé ce délai, demandez à un administrateur de vous renvoyer une invitation.")
            ->line("Si vous n'attendiez pas cette invitation, ignorez simplement cet e-mail.")
            ->salutation($church);
    }
}
