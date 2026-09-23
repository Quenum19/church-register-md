<?php

namespace App\Models;

use Database\Factories\VisitorNoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Note horodatée sur un visiteur. Supprimable par son auteur ou un super_admin.
 *
 * @property int $id
 * @property int $visitor_id
 * @property int|null $user_id
 * @property string $body
 * @property Carbon|null $created_at
 * @property-read Visitor $visitor
 * @property-read User|null $author
 */
class VisitorNote extends Model
{
    /** @use HasFactory<VisitorNoteFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'visitor_id',
        'user_id',
        'body',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visitor_id' => 'integer',
            'user_id' => 'integer',
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
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
