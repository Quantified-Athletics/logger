<?php

namespace App\Sync\Actions;

use App\Sync\Models\AthleteQueueItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SyncAthleteQueueAction
{
    public function __construct(
        private readonly DeleteAthleteQueueItemAction $deleteAthleteQueueItemAction,
    ) {}

    /**
     * Store an athlete's pushed queue items by last write wins, then tombstone the explicit deletions.
     *
     * An item replaces the stored one only when its `updatedAt` is strictly newer. A tombstone is lifted
     * only by an `updatedAt` newer than both its `deleted_at` and the stored `updatedAt`. A coach item is
     * skipped and counted. An item missing from the push is never touched. An id both pushed and in
     * `deletedIds` ends tombstoned, because deletions are applied last.
     *
     * @param  list<array{id: string, updatedAt: string, source: mixed, json: string}>  $items
     * @param  list<string>  $deletedIds
     * @return array{stored: int, skipped_coach: int}
     */
    public function execute(int $userId, array $items, array $deletedIds): array
    {
        $skippedCoach = 0;
        $incoming = [];
        foreach ($items as $item) {
            if ($item['source'] === 'coach') {
                $skippedCoach++;

                continue;
            }
            $incoming[] = $item;
        }

        $stored = 0;

        DB::transaction(function () use ($userId, $incoming, $deletedIds, &$stored): void {
            $existing = AthleteQueueItem::query()
                ->where('user_id', $userId)
                ->whereIn('item_id', array_column($incoming, 'id'))
                ->get()
                ->keyBy('item_id');

            foreach ($incoming as $item) {
                $updatedAt = Carbon::parse($item['updatedAt'])->utc();
                $queueItem = $existing->get($item['id']);

                if ($queueItem !== null && ! $this->replaces($updatedAt, $queueItem)) {
                    continue;
                }

                $queueItem ??= new AthleteQueueItem(['user_id' => $userId, 'item_id' => $item['id']]);
                $queueItem->fill([
                    'item' => $item['json'],
                    'item_updated_at' => $updatedAt,
                    'deleted_at' => null,
                ])->save();
                $stored++;
            }

            $this->deleteAthleteQueueItemAction->execute($userId, $deletedIds);
        });

        return ['stored' => $stored, 'skipped_coach' => $skippedCoach];
    }

    private function replaces(Carbon $updatedAt, AthleteQueueItem $queueItem): bool
    {
        if (! $updatedAt->gt($queueItem->item_updated_at)) {
            return false;
        }

        return $queueItem->deleted_at === null || $updatedAt->gt($queueItem->deleted_at);
    }
}
