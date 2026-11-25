<?php

namespace App\Sync\Actions;

use App\Sync\Models\AthleteQueueItem;
use Illuminate\Support\Carbon;

class DeleteAthleteQueueItemAction
{
    /**
     * Tombstone an athlete's queue items, so other devices learn of the deletion. An id the athlete
     * has no item for, or one already tombstoned, is left as it is: deleting is idempotent.
     *
     * @param  list<string>  $itemIds
     */
    public function execute(int $userId, array $itemIds): void
    {
        if ($itemIds === []) {
            return;
        }

        $now = Carbon::now();

        AthleteQueueItem::query()
            ->where('user_id', $userId)
            ->whereIn('item_id', array_values(array_unique($itemIds)))
            ->live()
            ->get()
            ->each(fn (AthleteQueueItem $queueItem): bool => $queueItem->fill(['deleted_at' => $now])->save());
    }
}
