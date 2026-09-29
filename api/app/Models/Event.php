<?php

namespace App\Models;

use App\Services\SettingsService;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Événement (culte spécial, évangélisation) doté d'un lien public dédié `/e/{slug}`.
 *
 * Une personne inscrite par ce lien entre dans le parcours normal (1re, puis 2e et 3e visites
 * lors des cultes ordinaires) : seule la visite enregistrée depuis le lien porte `event_id`.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property Carbon|null $event_date
 * @property bool $active
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $creator
 * @property-read int|null $visits_count agrégat chargé par scopeWithCounts()
 * @property-read int|null $visitors_count agrégat chargé par scopeWithCounts()
 */
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    public const NAME_MAX_LENGTH = 120;

    public const SLUG_MAX_LENGTH = 60;

    /** Forme du slug (contrat d'API §4) : minuscules, chiffres, tirets simples internes. */
    public const SLUG_PATTERN = '^[a-z0-9]+(-[a-z0-9]+)*$';

    /** Segment du lien public : {public_url}/e/{slug}. */
    public const PUBLIC_PATH = 'e';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'event_date',
        'active',
        'created_by',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'active' => 'boolean',
            'created_by' => 'integer',
        ];
    }

    /**
     * @return HasMany<Visit, $this>
     */
    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Ordre de la liste admin : date la plus récente d'abord (les événements sans date en
     * dernier, MariaDB classant NULL en bas d'un tri décroissant), puis nom.
     *
     * @param  Builder<Event>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderByDesc('event_date')->orderBy('name')->orderBy('id');
    }

    /**
     * @param  Builder<Event>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /**
     * Ajoute `visits_count` (visites rattachées) et `visitors_count` (personnes distinctes)
     * en sous-requêtes corrélées : aucun N+1, quel que soit le nombre d'événements.
     *
     * @param  Builder<Event>  $query
     */
    public function scopeWithCounts(Builder $query): void
    {
        $query->select('events.*')
            ->withCount('visits')
            ->addSelect(['visitors_count' => Visit::query()
                ->selectRaw('count(distinct visits.visitor_id)')
                ->whereColumn('visits.event_id', 'events.id'),
            ]);
    }

    /**
     * Lien public de l'événement : {public_url des paramètres}/e/{slug}.
     */
    public function publicUrl(): string
    {
        $base = rtrim(app(SettingsService::class)->publicUrl(), '/');

        return $base.'/'.self::PUBLIC_PATH.'/'.$this->slug;
    }
}
