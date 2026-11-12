<?php

namespace App\Sync\Controllers;

use App\Sync\Models\PrismAthletePublication;
use App\Sync\Requests\ListPrismAthletePublicationsRequest;
use Illuminate\Http\JsonResponse;

class AthletePublicationFeedController
{
    /**
     * Publications after the given id, oldest first, one page at a time. Logger keeps no
     * "picked up" state: the reader remembers the last id it handled.
     *
     * GET /api/sync/athlete-publications?after=<id>
     */
    public function index(ListPrismAthletePublicationsRequest $request): JsonResponse
    {
        $publications = PrismAthletePublication::query()
            ->with('publishedBy:id,name')
            ->where('id', '>', $request->afterId())
            ->orderBy('id')
            ->limit(PrismAthletePublication::READ_PAGE_SIZE)
            ->get();

        return response()->json([
            'publications' => $publications->map(fn (PrismAthletePublication $publication): array => [
                'id' => $publication->id,
                'gym_id' => $publication->gym_id,
                'program_uuid' => $publication->program_uuid,
                'track' => $publication->track,
                'start_date' => $publication->start_date->toDateString(),
                'raw' => json_decode($publication->getRawOriginal('raw')),
                'published_by' => $publication->publishedBy
                    ? ['id' => $publication->publishedBy->id, 'name' => $publication->publishedBy->name]
                    : null,
                'published_at' => $publication->published_at->toIso8601String(),
            ])->all(),
        ]);
    }
}
