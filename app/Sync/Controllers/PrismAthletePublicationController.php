<?php

namespace App\Sync\Controllers;

use App\Sync\Actions\StorePrismAthletePublicationAction;
use App\Sync\Controllers\Concerns\ValidatesGym;
use App\Sync\Requests\StorePrismAthletePublicationRequest;
use Illuminate\Http\JsonResponse;

class PrismAthletePublicationController
{
    use ValidatesGym;

    public function __construct(
        private readonly StorePrismAthletePublicationAction $storePublicationAction,
    ) {}

    /**
     * Publish a Prism program to Athlete (admins only; see the request's authorize()).
     *
     * POST /api/sync/prism/{gym}/programs/{program_uuid}/athlete-publications
     */
    public function store(StorePrismAthletePublicationRequest $request, string $gym, string $program_uuid): JsonResponse
    {
        $this->findGymOrFail($gym);

        $publication = $this->storePublicationAction->execute(
            $gym,
            $program_uuid,
            $request->validated('track'),
            $request->validated('start_date'),
            $request->rawJson(),
            $request->user()?->getKey(),
        );

        return response()->json([
            'id' => $publication->id,
            'published_at' => $publication->published_at->toIso8601String(),
        ], 201);
    }
}
