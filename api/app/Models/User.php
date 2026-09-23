<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * Compte du dashboard admin.
 *
 * PAS de `Laravel\Sanctum\HasApiTokens` : l'authentification se fait exclusivement par cookie
 * de session (Sanctum SPA) et aucun jeton personnel n'est créé nulle part. Sans ce trait,
 * `Laravel\Sanctum\Guard::supportsTokens()` renvoie false : un en-tête `Authorization: Bearer …`
 * ne peut donc PAS authentifier un administrateur, même si une ligne était insérée à la main
 * dans `personal_access_tokens`. Aucun jeton porteur à durée illimitée ne circule.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $password
 * @property Role $role
 * @property bool $is_active
 * @property int $failed_attempts
 * @property Carbon|null $locked_until
 * @property string|null $two_factor_secret
 * @property list<string>|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property Carbon|null $last_login_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Attributs assignables en masse. Les champs de sécurité (verrouillage, 2FA,
     * dernière connexion) sont volontairement exclus : ils s'écrivent explicitement.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'lecteur',
        'is_active' => true,
        'failed_attempts' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Hachage argon2id (config/hashing.php) géré par Laravel : ne jamais appeler Hash::make ici.
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
            'failed_attempts' => 'integer',
            'locked_until' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Abilities effectives du compte (vide si le compte est désactivé).
     * Utilisé par GET /api/auth/me et par les Gates.
     *
     * @return list<string>
     */
    public function abilities(): array
    {
        return $this->is_active ? $this->role->abilities() : [];
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === Role::SuperAdmin;
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Invitation en attente : aucun mot de passe n'a encore été choisi.
     */
    public function isInvitationPending(): bool
    {
        return $this->password === null;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /**
     * @return HasMany<VisitorNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(VisitorNote::class);
    }

    /**
     * @return HasMany<AuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
