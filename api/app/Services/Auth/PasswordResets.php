<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Notifications\Auth\InvitationNotification;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Services\AuditLogger;
use Illuminate\Auth\Passwords\PasswordBroker as ConcretePasswordBroker;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Contracts\Auth\PasswordBrokerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use LogicException;

/**
 * Liens de réinitialisation (broker « users », table `password_reset_tokens`, 60 min) et
 * d'invitation (broker « invitations », table `invitation_tokens`, 48 h).
 *
 * Chaque broker a sa table : la purge quotidienne des jetons expirés (`auth:clear-resets [broker]`)
 * applique à chacun sa propre durée. En plus, les jetons ne se mélangent jamais :
 * - une invitation n'est émise et acceptée que pour un compte SANS mot de passe ;
 * - une réinitialisation n'est émise et acceptée que pour un compte qui en a un.
 */
class PasswordResets
{
    public const RESET_BROKER = 'users';

    public const INVITATION_BROKER = 'invitations';

    public function __construct(
        private readonly PasswordBrokerFactory $brokers,
        private readonly UserSessions $sessions,
        private readonly LoginThrottle $throttle,
        private readonly AuditLogger $audit,
        private readonly Request $request,
    ) {}

    /**
     * « Mot de passe oublié » : envoie un lien seulement à un compte actif ayant un mot de passe.
     * Aucune différence observable sinon (réponse neutre, durée minimale garantie par le broker).
     */
    public function sendResetLink(string $email): void
    {
        $this->broker(self::RESET_BROKER)->sendResetLink(
            ['email' => $email, 'is_active' => true, 'state' => self::withPassword()],
            static function (User $user, #[\SensitiveParameter] string $token): string {
                $user->notify(new ResetPasswordNotification($token));

                return PasswordBroker::RESET_LINK_SENT;
            },
        );
    }

    /**
     * Invitation (ou renvoi) : nouveau jeton 48 h, l'éventuel jeton précédent est remplacé.
     */
    public function sendInvitation(User $user): void
    {
        if (! $user->isInvitationPending()) {
            throw new LogicException('Une invitation ne vise qu\'un compte sans mot de passe.');
        }

        $token = $this->concreteBroker(self::INVITATION_BROKER)->createToken($user);

        $user->notify(new InvitationNotification($token));
    }

    /**
     * Supprime tout lien de réinitialisation ou d'invitation en cours pour cette adresse.
     */
    public function forget(string $email): void
    {
        $user = new User(['email' => $email]);

        foreach ([self::RESET_BROKER, self::INVITATION_BROKER] as $broker) {
            $this->concreteBroker($broker)->getRepository()->delete($user);
        }
    }

    /**
     * Définit le mot de passe à partir d'un lien de réinitialisation ou d'invitation.
     * Toutes les sessions du compte sont supprimées.
     *
     * `is_active` est exigé à la CONSOMMATION, en plus de la purge des jetons faite à la
     * désactivation : un lien parti avant la désactivation ne doit jamais rouvrir le compte.
     *
     * @return 'reset'|'invitation'|null origine du jeton accepté, null si le lien est invalide ou expiré
     */
    public function reset(string $email, #[\SensitiveParameter] string $token, #[\SensitiveParameter] string $password): ?string
    {
        $via = User::query()->where('email', $email)->whereNull('password')->exists() ? 'invitation' : 'reset';

        $status = $this->broker($via === 'invitation' ? self::INVITATION_BROKER : self::RESET_BROKER)->reset(
            [
                'email' => $email,
                'token' => $token,
                'password' => $password,
                'is_active' => true,
                'state' => $via === 'invitation' ? self::withoutPassword() : self::withPassword(),
            ],
            function (User $user, #[\SensitiveParameter] string $password) use ($via): void {
                // Mot de passe en clair : le cast `hashed` du modèle le hache UNE fois (argon2id).
                $user->forceFill(['password' => $password, 'failed_attempts' => 0, 'locked_until' => null]);
                $user->setRememberToken(Str::random(60));
                $user->save();

                $this->sessions->destroyAll($user);
                // L'IP courante est celle d'où la personne vient de choisir son mot de passe :
                // son éventuel blocage (20 échecs) est levé pour qu'elle se reconnecte aussitôt.
                $this->throttle->clearAccount($user, $this->request->ip());
                $this->audit->log('auth.password_changed', null, ['via' => $via], $user);
            },
        );

        return $status === PasswordBroker::PASSWORD_RESET ? $via : null;
    }

    private function broker(string $name): PasswordBroker
    {
        return $this->brokers->broker($name);
    }

    private function concreteBroker(string $name): ConcretePasswordBroker
    {
        $broker = $this->broker($name);

        if (! $broker instanceof ConcretePasswordBroker) {
            throw new LogicException("Broker de mots de passe [{$name}] inattendu.");
        }

        return $broker;
    }

    /**
     * @return \Closure(Builder<User>): void
     */
    private static function withPassword(): \Closure
    {
        return static function (Builder $query): void {
            $query->whereNotNull('password');
        };
    }

    /**
     * @return \Closure(Builder<User>): void
     */
    private static function withoutPassword(): \Closure
    {
        return static function (Builder $query): void {
            $query->whereNull('password');
        };
    }
}
