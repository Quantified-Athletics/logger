<?php

namespace App\Sync\Controllers;

use App\Sync\Actions\UpsertPrismExerciseFamiliesAction;
use App\Sync\Controllers\Concerns\ValidatesGym;
use App\Sync\Requests\StorePrismExerciseFamiliesRequest;
use Illuminate\Http\JsonResponse;

class PrismExerciseFamilyController
{
    use ValidatesGym;

    public function __construct(
        private readonly UpsertPrismExerciseFamiliesAction $upsertExerciseFamiliesAction,
    ) {
    }

    /**
     * Store or update Prism exercise families and write tombstones for removals.
     */
    public function store(StorePrismExerciseFamiliesRequest $request, string $gym): JsonResponse
    {
        $this->findGymOrFail($gym);

        $deviceId = $request->attributes->get('device_id');
        $upserts = $request->validated('upserts', []);
        $removals = $request->validated('removals', []);

        $updatedAt = $this->upsertExerciseFamiliesAction->execute($gym, $upserts, $removals, $deviceId);

        return response()->json([
            'status' => 'ok',
            'updated_at' => $updatedAt->toIso8601String(),
        ]);
    }
}
