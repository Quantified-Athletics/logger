<?php

namespace App\Sync\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrismExerciseFamilyMember extends Model
{
    protected $table = 'prism_exercise_family_members';

    protected $fillable = [
        'gym_id',
        'exercise_id',
        'family_id',
        'is_member',
        'device_id',
        'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'is_member' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    public function gym(): BelongsTo
    {
        return $this->belongsTo(Gym::class, 'gym_id', 'id');
    }
}
