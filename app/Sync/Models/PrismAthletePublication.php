<?php

namespace App\Sync\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A program an admin published to Athlete. Rows are append-only; Athlete pulls them.
 */
class PrismAthletePublication extends Model
{
    /** Athlete tracks a program can be published to. */
    public const TRACKS = ['peak', 'sa'];

    /** The only ability of the token Athlete's GitHub Action reads publications with. */
    public const READ_ABILITY = 'athlete-publications:read';

    /** Most publications one read returns. */
    public const READ_PAGE_SIZE = 20;

    /** Largest raw file accepted, in bytes of JSON. */
    public const RAW_MAX_BYTES = 2 * 1024 * 1024;

    protected $table = 'prism_athlete_publications';

    protected $fillable = [
        'gym_id',
        'program_uuid',
        'track',
        'start_date',
        'raw',
        'published_by_user_id',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'published_at' => 'datetime',
        ];
    }

    public function gym(): BelongsTo
    {
        return $this->belongsTo(Gym::class, 'gym_id', 'id');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }
}
