<?php

namespace App\Sync\Controllers;

use App\Sync\Actions\UpsertPrismExerciseLimitsAction;
use App\Sync\Controllers\Concerns\ValidatesGym;
use App\Sync\Requests\StorePrismExerciseLimitsRequest;
use Illuminate\Http\JsonResponse;

class PrismExerciseLimitsController
{
    use ValidatesGym;

    public function __construct(
        private readonly UpsertPrismExerciseLimitsAction $upsertLimitsAction,
    ) {
    }

    /**
     * Store or update Prism exercise families, family memberships and exercise caps, and write tombstones for removals.
     */
    public function store(StorePrismExerciseLimitsRequest $request, string $gym): JsonResponse
    {
        $this->findGymOrFail($gym);

        $deviceId = $request->attributes->get('device_id');

        $updatedAt = $this->upsertLimitsAction->execute(
            $gym,
            $request->validated('families'),
            $request->validated('members'),
            $request->validated('caps'),
            $deviceId,
        );

        return response()->json([
            'status' => 'ok',
            'updated_at' => $updatedAt->toIso8601String(),
        ]);
    }
}
