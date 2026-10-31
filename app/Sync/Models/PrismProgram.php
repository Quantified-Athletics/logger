<?php

namespace App\Sync\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrismProgram extends Model
{
    protected $table = 'prism_programs';

    protected $fillable = [
        'gym_id',
        'program_uuid',
        'name',
        'week_count',
        'start_date',
        'archived_at',
        'device_id',
    ];

    /**
     * start_date is intentionally not cast: it is a plain calendar date (YYYY-MM-DD) with no
     * time or timezone, and it is stored and returned exactly as the client sent it.
     */
    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    public function gym(): BelongsTo
    {
        return $this->belongsTo(Gym::class, 'gym_id', 'id');
    }
}
