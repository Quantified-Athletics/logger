<?php

namespace App\Sync\Models;

use App\Models\User;
use Database\Factories\Sync\PrismCoachPublicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Prism program snapshot a coach published to one athlete. Each publish is a new row; a
 * program's current publication for an athlete is its latest by `published_at`, if not revoked.
 */
class PrismCoachPublication extends Model
{
    /** @use HasFactory<PrismCoachPublicationFactory> */
    use HasFactory;

    protected $table = 'prism_coach_publications';

    /** Keeps the microseconds of the datetime(6) columns, so publishes in one second still order. */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'gym_id',
        'program_uuid',
        'recipient_user_id',
        'start_date',
        'raw',
        'published_by_user_id',
        'published_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'published_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function newFactory(): PrismCoachPublicationFactory
    {
        return PrismCoachPublicationFactory::new();
    }

    public function gym(): BelongsTo
    {
        return $this->belongsTo(Gym::class, 'gym_id', 'id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }
}
