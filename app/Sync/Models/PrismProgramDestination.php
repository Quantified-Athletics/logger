<?php

namespace App\Sync\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrismProgramDestination extends Model
{
    public const TYPE_GOOGLE_SHEETS = 'google_sheets';

    /** Destination types the server accepts; matches Prism's publishDestinationRegistry ids. */
    public const TYPES = [self::TYPE_GOOGLE_SHEETS];

    protected $table = 'prism_program_destinations';

    protected $fillable = [
        'gym_id',
        'program_uuid',
        'type',
        'spreadsheet_id',
        'sheet_id',
        'title',
        'linked_by_user_id',
        'linked_at',
        'device_id',
        'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'sheet_id' => 'integer',
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
