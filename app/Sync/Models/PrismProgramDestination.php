<?php

namespace App\Sync\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrismProgramDestination extends Model
{
    public const TYPE_GOOGLE_SHEETS = 'google_sheets';

    public const TYPE_MOMENCE = 'momence';

    /** Momence tracks a `momence` destination can publish to. */
    public const MOMENCE_TRACKS = ['peak', 'sa'];

    /** Destination types the server accepts; matches Prism's publishDestinationRegistry ids. */
    public const TYPES = [self::TYPE_GOOGLE_SHEETS, self::TYPE_MOMENCE];

    protected $table = 'prism_program_destinations';

    protected $fillable = [
        'gym_id',
        'program_uuid',
        'type',
        'spreadsheet_id',
        'sheet_id',
        'title',
        'momence_track',
        'momence_cycle_prefix',
        'momence_bridge_prefix',
        'momence_bridge_weeks',
        'linked_by_user_id',
        'linked_at',
        'device_id',
        'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'sheet_id' => 'integer',
            'momence_bridge_weeks' => 'integer',
            'linked_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function gym(): BelongsTo
    {
        return $this->belongsTo(Gym::class, 'gym_id', 'id');
    }

    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by_user_id');
    }
}
