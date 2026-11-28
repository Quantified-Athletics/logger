<?php

namespace App\Sync\Controllers;

use App\Sync\Controllers\Concerns\ValidatesGym;
use App\Sync\Models\PrismAthletePublication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class TrackProgramController
{
    use ValidatesGym;

    /**
     * A gym's current track programs, for Athlete without sign-in. The ETag hashes the sorted
     * current publication ids, so it is computed, and a 304 answered, without loading `raw`; the
     * 200 body is cached by ETag, so `raw` is read once per publish rather than once per athlete.
     *
     * GET /api/sync/gyms/{gym}/track-programs
     */
    public function index(Request $request, string $gym): Response
    {
        $this->findGymOrFail($gym);

        $currentIds = PrismAthletePublication::query()
            ->currentForGym($gym)
            ->orderBy('prism_athlete_publications.id')
            ->pluck('prism_athlete_publications.id')
            ->all();

        $etag = hash('sha256', 'track-programs:'.$gym.':'.implode(',', $currentIds));

        $notModified = response()->noContent()->setEtag($etag);
        if ($notModified->isNotModified($request)) {
            return $notModified;
        }

        $body = Cache::remember('track-programs:'.$etag, now()->addDay(), fn (): string => $this->body($currentIds));

        return JsonResponse::fromJsonString($body)->setEtag($etag);
    }

    /**
     * @param  list<int>  $publicationIds
     */
    private function body(array $publicationIds): string
    {
        $publications = PrismAthletePublication::query()
            ->whereIn('id', $publicationIds)
            ->orderBy('track')
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        return json_encode([
            'programs' => $publications->map(fn (PrismAthletePublication $publication): array => $this->program($publication))->all(),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{publication_id: int, program_uuid: string, program_name: mixed, track: string, start_date: string, published_at: string, raw: mixed}
     */
    private function program(PrismAthletePublication $publication): array
    {
        $raw = json_decode($publication->getRawOriginal('raw'));

        return [
            'publication_id' => $publication->id,
            'program_uuid' => $publication->program_uuid,
            'program_name' => $raw->metadata->program_name ?? null,
            'track' => $publication->track,
            'start_date' => $publication->start_date->toDateString(),
            'published_at' => $publication->published_at->toIso8601String(),
            'raw' => $raw,
        ];
    }
}
