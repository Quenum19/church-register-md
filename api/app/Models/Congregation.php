<?php

namespace App\Models;

use Database\Factories\CongregationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Congrégation de l'Église : l'assemblée à laquelle une personne appartient.
 *
 * Distincte de la famille d'accueil (App\Models\Family), qui organise le service par rotation
 * mensuelle : quelqu'un peut servir dans la famille Force et appartenir à la congrégation
 * Puissance. Sept congrégations portent le nom d'une famille, ce sont pourtant deux
 * appartenances différentes.
 *
 * @property int $id
 * @property string $name
 * @property int $position
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Visitor> $invitedVisitors
 */
class Congregation extends Model
{
    /** @use HasFactory<CongregationFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'position',
        'active',
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
            'position' => 'integer',
            'active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<Congregation>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }

    /**
     * @param  Builder<Congregation>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /**
     * Visiteurs dont l'invitant appartient à cette congrégation.
     *
     * @return HasMany<Visitor, $this>
     */
    public function invitedVisitors(): HasMany
    {
        return $this->hasMany(Visitor::class, 'inviter_congregation_id');
    }
}
