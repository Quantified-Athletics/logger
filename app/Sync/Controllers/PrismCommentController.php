<?php

namespace App\Sync\Controllers;

use App\Sync\Actions\UpsertPrismCommentsAction;
use App\Sync\Controllers\Concerns\ValidatesGym;
use App\Sync\Requests\StorePrismCommentsRequest;
use Illuminate\Http\JsonResponse;

class PrismCommentController
{
    use ValidatesGym;

    public function __construct(
        private readonly UpsertPrismCommentsAction $upsertCommentsAction,
    ) {}

    /**
     * Add, edit, restore or delete comments on a Prism program's workouts.
     *
     * POST /api/sync/prism/{gym}/programs/{program_uuid}/comments
     */
    public function store(StorePrismCommentsRequest $request, string $gym, string $program_uuid): JsonResponse
    {
        $this->findGymOrFail($gym);

        $updatedAt = $this->upsertCommentsAction->execute(
            $gym,
            $program_uuid,
            $request->validated('upserts', []),
            $request->validated('removals', []),
            (int) $request->user()->getKey(),
            $request->attributes->get('device_id'),
        );

        if ($updatedAt === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Only the author can edit or delete a comment.',
            ], 403);
        }

        return response()->json([
            'status' => 'ok',
            'updated_at' => $updatedAt->toIso8601String(),
        ]);
    }
}
