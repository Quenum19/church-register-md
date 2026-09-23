<?php

namespace App\Models;

use Database\Factories\FamilyRotationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Famille de service pour un mois donné (unique par année/mois).
 *
 * @property int $id
 * @property int $family_id
 * @property int $year
 * @property int $month
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Family $family
 */
class FamilyRotation extends Model
{
    /** @use HasFactory<FamilyRotationFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'family_id',
        'year',
        'month',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'family_id' => 'integer',
            'year' => 'integer',
            'month' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Family, $this>
     */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }
}
