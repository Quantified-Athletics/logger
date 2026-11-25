<?php

namespace App\Sync\Controllers;

use App\Sync\Actions\RevokePrismCoachPublicationsAction;
use App\Sync\Actions\StorePrismCoachPublicationAction;
use App\Sync\Controllers\Concerns\ValidatesGym;
use App\Sync\Requests\RevokePrismCoachPublicationsRequest;
use App\Sync\Requests\StorePrismCoachPublicationRequest;
use Illuminate\Http\JsonResponse;

class PrismCoachPublicationController
{
    use ValidatesGym;

    public function __construct(
        private readonly StorePrismCoachPublicationAction $storePublicationAction,
        private readonly RevokePrismCoachPublicationsAction $revokePublicationsAction,
    ) {}

    /**
     * Publish a Prism program to one athlete, named by the email of an existing account (admins only).
     *
     * POST /api/sync/prism/{gym}/programs/{program_uuid}/coach-publications
     */
    public function store(StorePrismCoachPublicationRequest $request, string $gym, string $program_uuid): JsonResponse
    {
        $this->findGymOrFail($gym);

        $recipient = $request->recipient();

        if (! $recipient) {
            return $this->unknownRecipient();
        }

        $publication = $this->storePublicationAction->execute(
            $gym,
            $program_uuid,
            $recipient->getKey(),
            $request->validated('start_date'),
            $request->rawJson(),
            $request->user()?->getKey(),
        );

        return response()->json([
            'id' => $publication->id,
            'published_at' => $publication->published_at->toIso8601String(),
            'recipient' => ['name' => $recipient->name],
        ], 201);
    }

    /**
     * Revoke every publication of a program to one athlete (admins only).
     *
     * POST /api/sync/prism/{gym}/programs/{program_uuid}/coach-publications/revoke
     */
    public function revoke(RevokePrismCoachPublicationsRequest $request, string $gym, string $program_uuid): JsonResponse
    {
        $this->findGymOrFail($gym);

        $recipient = $request->recipient();

        if (! $recipient) {
            return $this->unknownRecipient();
        }

        return response()->json([
            'revoked' => $this->revokePublicationsAction->execute($gym, $program_uuid, $recipient->getKey()),
        ]);
    }

    private function unknownRecipient(): JsonResponse
    {
        return response()->json(['error' => 'unknown_recipient'], 422);
    }
}
