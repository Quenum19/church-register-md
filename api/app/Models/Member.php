<?php

namespace App\Models;

use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Conversion d'un visiteur en membre (au plus une par visiteur).
 *
 * @property int $id
 * @property int $visitor_id
 * @property int|null $converted_by
 * @property Carbon $converted_at
 * @property-read Visitor $visitor
 * @property-read User|null $convertedBy
 */
class Member extends Model
{
    /** @use HasFactory<MemberFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'visitor_id',
        'converted_by',
        'converted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visitor_id' => 'integer',
            'converted_by' => 'integer',
            'converted_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function convertedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_by');
    }
}
