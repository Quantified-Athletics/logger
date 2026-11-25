<?php

namespace App\Sync\Controllers;

use App\Sync\Actions\DeleteAthleteQueueItemAction;
use App\Sync\Actions\SyncAthleteQueueAction;
use App\Sync\Services\AthleteQueueStateService;
use App\Sync\Requests\SyncAthleteQueueRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AthleteQueueController
{
    public function __construct(
        private readonly SyncAthleteQueueAction $syncAthleteQueueAction,
        private readonly DeleteAthleteQueueItemAction $deleteAthleteQueueItemAction,
        private readonly AthleteQueueStateService $athleteQueueStateService,
    ) {}

    /**
     * Serve the athlete's whole queue: every live item as stored, and every tombstoned id.
     *
     * GET /api/sync/queue/changes
     */
    public function changes(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->getKey();

        return response()->json([
            'items' => $this->athleteQueueStateService->liveItems($userId),
            'deleted_ids' => $this->athleteQueueStateService->deletedItemIds($userId),
        ], 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Store the athlete's pushed queue items and tombstone the ones they deleted.
     *
     * POST /api/sync/queue/sync
     */
    public function sync(SyncAthleteQueueRequest $request): JsonResponse
    {
        $result = $this->syncAthleteQueueAction->execute(
            (int) $request->user()->getKey(),
            $request->queueItems(),
            $request->deletedIds(),
        );

        return response()->json([
            'ok' => true,
            'stored' => $result['stored'],
            'skipped_coach' => $result['skipped_coach'],
        ]);
    }

    /**
     * Tombstone one of the athlete's queue items. An unknown id is fine.
     *
     * DELETE /api/sync/queue/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->deleteAthleteQueueItemAction->execute((int) $request->user()->getKey(), [$id]);

        return response()->json(['ok' => true]);
    }
}
