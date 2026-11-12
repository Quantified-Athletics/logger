<?php

namespace App\Sync\Actions;

use App\Sync\Models\PrismAthletePublication;

class StorePrismAthletePublicationAction
{
    /**
     * Record a "publish to Athlete" request. Always a new row: publishing the same program
     * again is a new publication, and earlier ones are left as they were.
     */
    public function execute(
        string $gymId,
        string $programUuid,
        string $track,
        string $startDate,
        string $rawJson,
        ?int $userId,
    ): PrismAthletePublication {
        $publication = new PrismAthletePublication([
            'gym_id' => $gymId,
            'program_uuid' => $programUuid,
            'track' => $track,
            'start_date' => $startDate,
            'raw' => $rawJson,
            'published_by_user_id' => $userId,
        ]);
        $publication->published_at = $publication->freshTimestamp();
        $publication->save();

        return $publication;
    }
}
