<?php

namespace App\Sync\Controllers;

use App\Sync\Actions\UpsertPrismProgramDestinationsAction;
use App\Sync\Controllers\Concerns\ValidatesGym;
use App\Sync\Requests\StorePrismProgramDestinationsRequest;
use Illuminate\Http\JsonResponse;

class PrismProgramDestinationController
{
    use ValidatesGym;

    public function __construct(
        private readonly UpsertPrismProgramDestinationsAction $upsertDestinationsAction,
    ) {}

    /**
     * Link or unlink a Prism program's publish destinations.
     *
     * POST /api/sync/prism/{gym}/programs/{program_uuid}/destinations
     */
    public function store(StorePrismProgramDestinationsRequest $request, string $gym, string $program_uuid): JsonResponse
    {
        $this->findGymOrFail($gym);

        $updatedAt = $this->upsertDestinationsAction->execute(
            $gym,
            $program_uuid,
            $request->validated('upserts', []),
            $request->validated('removals', []),
            $request->user()?->getKey(),
            $request->attributes->get('device_id'),
        );

        return response()->json([
            'status' => 'ok',
            'updated_at' => $updatedAt->toIso8601String(),
        ]);
    }
}
