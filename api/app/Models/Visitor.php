<?php

namespace App\Models;

use App\Enums\Source;
use App\Enums\VisitorStatus;
use Database\Factories\VisitorFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Visiteur identifié par son numéro E.164.
 *
 * `status` n'est volontairement PAS assignable en masse : il n'est écrit que par
 * App\Services\VisitorStatusService::refresh().
 *
 * @property int $id
 * @property string $phone
 * @property string $full_name
 * @property string|null $whatsapp
 * @property string $commune
 * @property string $quartier
 * @property Source $source
 * @property string|null $source_other
 * @property string|null $invited_by
 * @property int|null $inviter_family_id
 * @property bool $wants_whatsapp_group
 * @property Carbon|null $consent_at
 * @property VisitorStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Family|null $inviterFamily
 * @property-read Member|null $member
 * @property-read Collection<int, Visit> $visits
 * @property-read Collection<int, VisitorNote> $notes
 * @property-read int|null $visits_count agrégat chargé par scopeWithVisitStats()
 * @property-read string|null $visits_min_visit_date date de 1re visite (YYYY-MM-DD), scopeWithVisitStats()
 * @property-read string|null $visits_max_visit_date date de dernière visite (YYYY-MM-DD), scopeWithVisitStats()
 */
class Visitor extends Model
{
    /** @use HasFactory<VisitorFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'phone',
        'full_name',
        'whatsapp',
        'commune',
        'quartier',
        'source',
        'source_other',
        'invited_by',
        'inviter_family_id',
        'wants_whatsapp_group',
        'consent_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'prospect',
        'wants_whatsapp_group' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => Source::class,
            'status' => VisitorStatus::class,
            'inviter_family_id' => 'integer',
            'wants_whatsapp_group' => 'boolean',
            'consent_at' => 'datetime',
        ];
    }

    /**
     * Visites, de la 1re à la 3e.
     *
     * @return HasMany<Visit, $this>
     */
    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class)->orderBy('visit_number');
    }

    /**
     * Notes, de la plus récente à la plus ancienne.
     *
     * @return HasMany<VisitorNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(VisitorNote::class)->latest('created_at')->latest('id');
    }

    /**
     * @return HasOne<Member, $this>
     */
    public function member(): HasOne
    {
        return $this->hasOne(Member::class);
    }

    /**
     * @return BelongsTo<Family, $this>
     */
    public function inviterFamily(): BelongsTo
    {
        return $this->belongsTo(Family::class, 'inviter_family_id');
    }

    public function isMember(): bool
    {
        return $this->status === VisitorStatus::Membre;
    }

    /**
     * Ajoute le nombre de visites et les dates de 1re / dernière visite en sous-requêtes
     * corrélées (`visits_count`, `visits_min_visit_date`, `visits_max_visit_date`) : aucun N+1.
     *
     * @param  Builder<Visitor>  $query
     */
    public function scopeWithVisitStats(Builder $query): void
    {
        $query->withCount('visits')
            ->withMin('visits', 'visit_date')
            ->withMax('visits', 'visit_date');
    }
}
