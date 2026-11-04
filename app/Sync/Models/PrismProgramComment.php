<?php

namespace App\Sync\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrismProgramComment extends Model
{
    /** Longest comment body accepted, in characters; matches Prism's validateCommentBody. */
    public const MAX_BODY_LENGTH = 5000;

    protected $table = 'prism_program_comments';

    protected $fillable = [
        'gym_id',
        'program_uuid',
        'uuid',
        'workout_uuid',
        'body',
        'author_user_id',
        'device_id',
        'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'deleted_at' => 'datetime',
        ];
    }

    public function gym(): BelongsTo
    {
        return $this->belongsTo(Gym::class, 'gym_id', 'id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
