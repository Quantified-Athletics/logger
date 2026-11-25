<?php

namespace App\Sync\Actions;

use App\Sync\Models\PrismCoachPublication;

class StorePrismCoachPublicationAction
{
    /**
     * Record a coach publication of a program to one athlete. Always a new row: the latest
     * publication is the athlete's current version, and earlier ones are left as they were.
     */
    public function execute(
        string $gymId,
        string $programUuid,
        int $recipientUserId,
        string $startDate,
        string $rawJson,
        ?int $publishedByUserId,
    ): PrismCoachPublication {
        $publication = new PrismCoachPublication([
            'gym_id' => $gymId,
            'program_uuid' => $programUuid,
            'recipient_user_id' => $recipientUserId,
            'start_date' => $startDate,
            'raw' => $rawJson,
            'published_by_user_id' => $publishedByUserId,
        ]);
        $publication->published_at = $publication->freshTimestamp();
        $publication->save();

        return $publication;
    }
}
