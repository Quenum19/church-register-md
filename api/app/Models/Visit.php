<?php

namespace App\Models;

use App\Casts\JsonObject;
use Database\Factories\VisitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Visite (1 à 3) d'un visiteur. Pas de colonne updated_at : une visite est immuable.
 *
 * @property int $id
 * @property int $visitor_id
 * @property int $visit_number
 * @property Carbon $visit_date
 * @property int|null $family_id
 * @property array<string, mixed> $answers objet JSON ({} pour la visite 1)
 * @property string $idempotency_key
 * @property Carbon|null $created_at
 * @property-read Visitor $visitor
 * @property-read Family|null $family
 */
class Visit extends Model
{
    /** @use HasFactory<VisitFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** Nombre maximal de visites enregistrées par visiteur. */
    public const MAX_VISITS = 3;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'visitor_id',
        'visit_number',
        'visit_date',
        'family_id',
        'answers',
        'idempotency_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visitor_id' => 'integer',
            'visit_number' => 'integer',
            'visit_date' => 'date',
            'family_id' => 'integer',
            'answers' => JsonObject::class,
        ];
    }

    /**
     * @return BelongsTo<Visitor, $this>
     */
    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class);
    }

    /**
     * @return BelongsTo<Family, $this>
     */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }
}
