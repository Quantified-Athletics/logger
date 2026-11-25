<?php

namespace App\Sync\Services;

use App\Sync\Models\AthleteQueueItem;

/**
 * Reads an athlete's stored workout queue for the pull and restore answers.
 */
class AthleteQueueStateService
{
    /**
     * Every live queue item of the athlete, decoded as stored, oldest `updatedAt` first.
     *
     * @return list<mixed>
     */
    public function liveItems(int $userId): array
    {
        return AthleteQueueItem::query()
            ->where('user_id', $userId)
            ->live()
            ->orderBy('item_updated_at')
            ->orderBy('item_id')
            ->get(['item'])
            ->map(fn (AthleteQueueItem $queueItem): mixed => $queueItem->itemPayload())
            ->values()
            ->all();
    }

    /**
     * The `item_id` of every tombstoned queue item of the athlete.
     *
     * @return list<string>
     */
    public function deletedItemIds(int $userId): array
    {
        return AthleteQueueItem::query()
            ->where('user_id', $userId)
            ->tombstoned()
            ->orderBy('item_id')
            ->pluck('item_id')
            ->values()
            ->all();
    }
}
