<?php

namespace App\Sync\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrismExerciseCap extends Model
{
    protected $table = 'prism_exercise_caps';

    protected $fillable = [
        'gym_id',
        'exercise_id',
        'cap_count',
        'cap_period',
        'device_id',
        'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'cap_count' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    public function gym(): BelongsTo
    {
        return $this->belongsTo(Gym::class, 'gym_id', 'id');
    }
}
