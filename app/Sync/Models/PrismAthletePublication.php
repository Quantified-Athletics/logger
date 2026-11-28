<?php

namespace App\Sync\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;

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

    /** How many days after its start date a gym's current track program is still served. */
    public const CURRENT_TRACK_PROGRAM_DAYS = 84;

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

    /**
     * The gym's current publication of each program: the latest by `published_at` (the higher id
     * breaks a tie), kept only when its start date is no more than 84 days ago.
     *
     * @param  Builder<PrismAthletePublication>  $query
     */
    public function scopeCurrentForGym(Builder $query, string $gymId): void
    {
        $query->where('prism_athlete_publications.gym_id', $gymId)
            ->where('prism_athlete_publications.start_date', '>=', today()->subDays(self::CURRENT_TRACK_PROGRAM_DAYS)->toDateString())
            ->whereNotExists(function (QueryBuilder $later): void {
                $later->selectRaw('1')
                    ->from('prism_athlete_publications as later')
                    ->whereColumn('later.gym_id', 'prism_athlete_publications.gym_id')
                    ->whereColumn('later.program_uuid', 'prism_athlete_publications.program_uuid')
                    ->where(function (QueryBuilder $newer): void {
                        $newer->whereColumn('later.published_at', '>', 'prism_athlete_publications.published_at')
                            ->orWhere(function (QueryBuilder $tie): void {
                                $tie->whereColumn('later.published_at', 'prism_athlete_publications.published_at')
                                    ->whereColumn('later.id', '>', 'prism_athlete_publications.id');
                            });
                    });
            });
    }
}
