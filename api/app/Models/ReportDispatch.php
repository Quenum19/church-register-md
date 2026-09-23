<?php

namespace App\Models;

use Database\Factories\ReportDispatchFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Trace d'envoi d'un rapport mensuel (unique par année/mois : envoi idempotent).
 *
 * @property int $id
 * @property int|null $family_id
 * @property int $year
 * @property int $month
 * @property Carbon $sent_at
 * @property list<string> $recipients
 * @property int|null $sent_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Family|null $family
 * @property-read User|null $sender
 */
class ReportDispatch extends Model
{
    /** @use HasFactory<ReportDispatchFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'family_id',
        'year',
        'month',
        'sent_at',
        'recipients',
        'sent_by',
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
            'sent_at' => 'datetime',
            'recipients' => 'array',
            'sent_by' => 'integer',
        ];
    }

    /**
     * Envoi du rapport d'un mois donné (au plus une ligne : unique(year, month)).
     *
     * @param  Builder<ReportDispatch>  $query
     */
    public function scopeForMonth(Builder $query, int $year, int $month): void
    {
        $query->where('year', $year)->where('month', $month);
    }

    /**
     * @return BelongsTo<Family, $this>
     */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /**
     * Auteur de l'envoi manuel (NULL = envoi automatique).
     *
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
