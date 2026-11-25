<?php

namespace App\Sync\Actions;

use App\Sync\Models\PrismCoachPublication;

class RevokePrismCoachPublicationsAction
{
    /**
     * Revoke every not-yet-revoked publication of one program to one athlete, and return how many
     * it revoked. Publications already revoked keep their original `revoked_at`.
     */
    public function execute(string $gymId, string $programUuid, int $recipientUserId): int
    {
        return PrismCoachPublication::query()
            ->where('gym_id', $gymId)
            ->where('program_uuid', $programUuid)
            ->where('recipient_user_id', $recipientUserId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => (new PrismCoachPublication)->fromDateTime(now())]);
    }
}
