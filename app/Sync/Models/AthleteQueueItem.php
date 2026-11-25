<?php

namespace App\Sync\Models;

use App\Models\User;
use Database\Factories\Sync\AthleteQueueItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item of an athlete's workout queue. `item` is the item's JSON text, never cast or interpreted
 * here; MySQL's `json` column may reorder object keys and respace it. `item_updated_at` is the
 * item's `updatedAt`, used for last-write-wins. A set `deleted_at` is a tombstone, not a soft
 * delete: the row stays visible to Eloquent so it can be served as a deletion and revived by a newer
 * push.
 */
class AthleteQueueItem extends Model
{
    /** @use HasFactory<AthleteQueueItemFactory> */
    use HasFactory;

    protected $table = 'athlete_queue_items';

    /** Keeps the microseconds of the datetime(6) columns, so writes in one second still order. */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'user_id',
        'item_id',
        'item',
        'item_updated_at',
        'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'item_updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    protected static function newFactory(): AthleteQueueItemFactory
    {
        return AthleteQueueItemFactory::new();
    }

    /**
     * The stored item decoded as objects, so an empty `{}` is served back as `{}`, not `[]`.
     */
    public function itemPayload(): mixed
    {
        return json_decode($this->item, false, 512, JSON_THROW_ON_ERROR);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<AthleteQueueItem>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('athlete_queue_items.deleted_at');
    }

    /**
     * @param  Builder<AthleteQueueItem>  $query
     */
    public function scopeTombstoned(Builder $query): void
    {
        $query->whereNotNull('athlete_queue_items.deleted_at');
    }
}
