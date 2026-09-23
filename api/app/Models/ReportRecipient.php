<?php

namespace App\Models;

use Database\Factories\ReportRecipientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Destinataire des rapports mensuels. `family_id` NULL = reçoit les rapports de toutes les familles.
 *
 * Rappel : l'index unique (family_id, email) ne bloque pas les doublons quand family_id est NULL
 * (sémantique SQL de NULL) : l'unicité doit aussi être validée côté application.
 *
 * @property int $id
 * @property int|null $family_id
 * @property string $name
 * @property string $email
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Family|null $family
 */
class ReportRecipient extends Model
{
    /** @use HasFactory<ReportRecipientFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'family_id',
        'name',
        'email',
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
            'family_id' => 'integer',
            'active' => 'boolean',
        ];
    }

    /**
     * Destinataires actifs d'un rapport : ceux de la famille + les globaux.
     *
     * @param  Builder<ReportRecipient>  $query
     */
    public function scopeForFamily(Builder $query, ?int $familyId): void
    {
        $query->where('active', true)
            ->where(function (Builder $query) use ($familyId): void {
                $query->whereNull('family_id');

                if ($familyId !== null) {
                    $query->orWhere('family_id', $familyId);
                }
            });
    }

    /**
     * @return BelongsTo<Family, $this>
     */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }
}
