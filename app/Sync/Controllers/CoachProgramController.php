<?php

namespace App\Sync\Controllers;

use App\Sync\Models\PrismCoachPublication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CoachProgramController
{
    /**
     * The signed-in athlete's current coach programs, oldest start date first. The ETag hashes the
     * sorted current publication ids, so it is computed, and a 304 answered, without loading `raw`.
     *
     * GET /api/sync/coach-programs
     */
    public function index(Request $request): Response
    {
        $recipientUserId = $request->user()->getKey();

        $currentIds = PrismCoachPublication::query()
            ->currentForRecipient($recipientUserId)
            ->orderBy('prism_coach_publications.id')
            ->pluck('prism_coach_publications.id')
            ->all();

        $etag = hash('sha256', 'coach-programs:'.implode(',', $currentIds));

        $notModified = response()->noContent()->setEtag($etag);
        if ($notModified->isNotModified($request)) {
            return $notModified;
        }

        $publications = PrismCoachPublication::query()
            ->with('publishedBy:id,name')
            ->whereIn('id', $currentIds)
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        return response()->json([
            'programs' => $publications->map(fn (PrismCoachPublication $publication): array => $this->program($publication))->all(),
        ])->setEtag($etag);
    }

    /**
     * @return array{publication_id: int, program_uuid: string, program_name: mixed, start_date: string, published_at: string, coach: array{name: ?string}, raw: mixed}
     */
    private function program(PrismCoachPublication $publication): array
    {
        $raw = json_decode($publication->getRawOriginal('raw'));

        return [
            'publication_id' => $publication->id,
            'program_uuid' => $publication->program_uuid,
            'program_name' => $raw->metadata->program_name ?? null,
            'start_date' => $publication->start_date->toDateString(),
            'published_at' => $publication->published_at->toIso8601String(),
            'coach' => ['name' => $publication->publishedBy?->name],
            'raw' => $raw,
        ];
    }
}
