<?php

namespace Tests\Feature\Sync\Prism;

use App\Models\Role;
use App\Models\User;
use App\Sync\Models\Gym;
use App\Sync\Models\PrismAthletePublication;
use App\Sync\Models\PrismCoachPublication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The signed-in athlete reads their own current coach programs, with an ETag (issue #21, S3).
 */
class CoachProgramsReadTest extends TestCase
{
    use RefreshDatabase;

    private User $coach;

    private User $athlete;

    private User $otherAthlete;

    protected function setUp(): void
    {
        parent::setUp();
        Gym::create(['id' => 'the-cove', 'name' => 'The Cove']);
        $this->coach = User::factory()->create(['name' => 'Coach C']);
        $this->coach->roles()->attach(Role::firstOrCreate(['name' => 'Admin']));
        $this->athlete = User::factory()->create(['name' => 'Athlete A']);
        $this->otherAthlete = User::factory()->create(['name' => 'Athlete B']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function raw(string $programUuid, string $programName, string $startDate): array
    {
        return [
            'metadata' => [
                'source' => 'prism',
                'program_uuid' => $programUuid,
                'program_name' => $programName,
                'track' => null,
                'start_date' => $startDate,
                'published_at' => '2026-11-24T10:00:00+00:00',
                'empty' => new \stdClass,
            ],
            'workouts' => [
                [
                    'id' => 'w1d1',
                    'week' => 1,
                    'day' => 1,
                    'dateLabel' => 'Mon 30 Nov',
                    'isRest' => false,
                    'text' => ["{$programName} day 1"],
                    '_formatting' => ['formattedText' => ["**{$programName}** day 1"]],
                ],
            ],
        ];
    }

    private function publish(
        User $recipient,
        string $programUuid,
        string $programName = 'Spring Block',
        string $startDate = '2026-11-30',
        ?string $publishedAt = null,
    ): PrismCoachPublication {
        return PrismCoachPublication::factory()->create([
            'gym_id' => 'the-cove',
            'program_uuid' => $programUuid,
            'recipient_user_id' => $recipient->id,
            'start_date' => $startDate,
            'raw' => json_encode(self::raw($programUuid, $programName, $startDate)),
            'published_by_user_id' => $this->coach->id,
            'published_at' => $publishedAt ? Carbon::parse($publishedAt) : now(),
        ]);
    }

    private function revoke(User $recipient, string $programUuid): void
    {
        PrismCoachPublication::query()
            ->where('recipient_user_id', $recipient->id)
            ->where('program_uuid', $programUuid)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function read(User $as, array $headers = []): TestResponse
    {
        Sanctum::actingAs($as, ['*']);

        return $this->withHeaders(['X-Device-Id' => 'dev-a'] + $headers)->getJson('/api/sync/coach-programs');
    }

    /**
     * @return list<int>
     */
    private function publicationIds(TestResponse $response): array
    {
        return array_column($response->json('programs'), 'publication_id');
    }

    public function test_returns_the_current_publication_with_the_frozen_shape(): void
    {
        $publication = $this->publish($this->athlete, 'prog-1', 'Spring Block', '2026-11-30');

        $response = $this->read($this->athlete);

        $response->assertOk()->assertExactJson([
            'programs' => [[
                'publication_id' => $publication->id,
                'program_uuid' => 'prog-1',
                'program_name' => 'Spring Block',
                'start_date' => '2026-11-30',
                'published_at' => $publication->published_at->toIso8601String(),
                'coach' => ['name' => 'Coach C'],
                'raw' => json_decode(json_encode(self::raw('prog-1', 'Spring Block', '2026-11-30')), true),
            ]],
        ]);
        $this->assertNotEmpty($response->headers->get('ETag'));
    }

    public function test_raw_keeps_empty_objects_as_objects(): void
    {
        $this->publish($this->athlete, 'prog-1');

        $body = json_decode($this->read($this->athlete)->getContent());

        $this->assertInstanceOf(\stdClass::class, $body->programs[0]->raw->metadata->empty);
    }

    public function test_an_athlete_with_nothing_gets_an_empty_list(): void
    {
        $this->read($this->athlete)->assertOk()->assertExactJson(['programs' => []]);
    }

    public function test_another_athletes_programs_are_not_returned(): void
    {
        $mine = $this->publish($this->athlete, 'prog-1');
        $theirs = $this->publish($this->otherAthlete, 'prog-2');
        $this->publish($this->otherAthlete, 'prog-1');

        $this->assertSame([$mine->id], $this->publicationIds($this->read($this->athlete)));
        $this->assertNotContains($theirs->id, $this->publicationIds($this->read($this->athlete)));
    }

    public function test_two_programs_come_back_oldest_start_date_first(): void
    {
        $later = $this->publish($this->athlete, 'prog-later', 'Summer Block', '2027-01-04', '2026-11-20 10:00:00');
        $earlier = $this->publish($this->athlete, 'prog-earlier', 'Spring Block', '2026-11-30', '2026-11-21 10:00:00');

        $this->assertSame([$earlier->id, $later->id], $this->publicationIds($this->read($this->athlete)));
    }

    public function test_a_revision_supersedes_the_earlier_publication(): void
    {
        $this->publish($this->athlete, 'prog-1', 'Spring Block', '2026-11-30', '2026-11-20 10:00:00');
        $revision = $this->publish($this->athlete, 'prog-1', 'Spring Block v2', '2026-12-07', '2026-11-21 10:00:00');

        $response = $this->read($this->athlete);

        $this->assertSame([$revision->id], $this->publicationIds($response));
        $response->assertJsonPath('programs.0.program_name', 'Spring Block v2');
        $response->assertJsonPath('programs.0.start_date', '2026-12-07');
    }

    public function test_the_latest_by_published_at_wins_even_when_inserted_first(): void
    {
        $latest = $this->publish($this->athlete, 'prog-1', 'Newest', '2026-11-30', '2026-11-22 10:00:00');
        $this->publish($this->athlete, 'prog-1', 'Older', '2026-11-30', '2026-11-21 10:00:00');

        $this->assertSame([$latest->id], $this->publicationIds($this->read($this->athlete)));
    }

    public function test_publications_in_the_same_microsecond_break_the_tie_by_id(): void
    {
        $this->publish($this->athlete, 'prog-1', 'First', '2026-11-30', '2026-11-22 10:00:00.123456');
        $second = $this->publish($this->athlete, 'prog-1', 'Second', '2026-11-30', '2026-11-22 10:00:00.123456');

        $this->assertSame([$second->id], $this->publicationIds($this->read($this->athlete)));
    }

    public function test_a_revoked_program_is_not_returned(): void
    {
        $this->publish($this->athlete, 'prog-1', 'Spring Block', '2026-11-30', '2026-11-20 10:00:00');
        $kept = $this->publish($this->athlete, 'prog-2', 'Summer Block', '2027-01-04', '2026-11-20 11:00:00');
        $this->revoke($this->athlete, 'prog-1');

        $this->assertSame([$kept->id], $this->publicationIds($this->read($this->athlete)));
    }

    public function test_a_revoked_latest_does_not_fall_back_to_an_earlier_unrevoked_one(): void
    {
        $this->publish($this->athlete, 'prog-1', 'Old', '2026-11-30', '2026-11-20 10:00:00');
        $latest = $this->publish($this->athlete, 'prog-1', 'New', '2026-11-30', '2026-11-21 10:00:00');
        $latest->update(['revoked_at' => now()]);

        $this->assertSame([], $this->publicationIds($this->read($this->athlete)));
    }

    public function test_a_republish_after_a_revoke_is_current_again(): void
    {
        $this->publish($this->athlete, 'prog-1', 'Spring Block', '2026-11-30', '2026-11-20 10:00:00');
        $this->revoke($this->athlete, 'prog-1');
        $republished = $this->publish($this->athlete, 'prog-1', 'Spring Block', '2026-11-30', '2026-11-22 10:00:00');

        $this->assertSame([$republished->id], $this->publicationIds($this->read($this->athlete)));
    }

    public function test_a_deleted_coach_comes_back_as_a_null_name(): void
    {
        $this->publish($this->athlete, 'prog-1');
        $this->coach->forceDelete();

        $this->read($this->athlete)->assertOk()->assertJsonPath('programs.0.coach', ['name' => null]);
    }

    public function test_a_matching_if_none_match_is_304_with_no_body(): void
    {
        $this->publish($this->athlete, 'prog-1');
        $etag = $this->read($this->athlete)->headers->get('ETag');

        $response = $this->read($this->athlete, ['If-None-Match' => $etag]);

        $response->assertStatus(304);
        $this->assertSame('', $response->getContent());
        $this->assertSame($etag, $response->headers->get('ETag'));
    }

    public function test_a_stale_if_none_match_gets_the_full_list(): void
    {
        $this->publish($this->athlete, 'prog-1');

        $this->read($this->athlete, ['If-None-Match' => '"stale"'])->assertOk()->assertJsonCount(1, 'programs');
    }

    public function test_the_etag_is_stable_when_nothing_changes(): void
    {
        $this->publish($this->athlete, 'prog-1');
        $this->publish($this->athlete, 'prog-2');

        $first = $this->read($this->athlete)->headers->get('ETag');

        $this->assertNotNull($first);
        $this->assertSame($first, $this->read($this->athlete)->headers->get('ETag'));
    }

    public function test_a_new_publication_a_revision_a_revoke_and_a_republish_each_change_the_etag(): void
    {
        $etags = [$this->read($this->athlete)->headers->get('ETag')];

        $this->publish($this->athlete, 'prog-1', 'Spring Block', '2026-11-30', '2026-11-20 10:00:00');
        $etags[] = $this->read($this->athlete)->headers->get('ETag');

        $this->publish($this->athlete, 'prog-1', 'Spring Block v2', '2026-11-30', '2026-11-21 10:00:00');
        $etags[] = $this->read($this->athlete)->headers->get('ETag');

        $this->revoke($this->athlete, 'prog-1');
        $etags[] = $this->read($this->athlete)->headers->get('ETag');

        $this->publish($this->athlete, 'prog-1', 'Spring Block v3', '2026-11-30', '2026-11-22 10:00:00');
        $etags[] = $this->read($this->athlete)->headers->get('ETag');

        $this->assertNotContains(null, $etags);
        for ($change = 1; $change < count($etags); $change++) {
            $this->assertNotSame($etags[$change - 1], $etags[$change], "change {$change} left the ETag unchanged");
        }
        $this->assertSame($etags[0], $etags[3], 'nothing current after the revoke, as at the start');
    }

    public function test_an_old_etag_is_not_answered_with_304_after_a_revoke(): void
    {
        $this->publish($this->athlete, 'prog-1');
        $etag = $this->read($this->athlete)->headers->get('ETag');
        $this->revoke($this->athlete, 'prog-1');

        $this->read($this->athlete, ['If-None-Match' => $etag])->assertOk()->assertExactJson(['programs' => []]);
    }

    public function test_another_athletes_publication_does_not_change_my_etag(): void
    {
        $this->publish($this->athlete, 'prog-1');
        $before = $this->read($this->athlete)->headers->get('ETag');
        $this->assertNotNull($before);

        $this->publish($this->otherAthlete, 'prog-2');

        $this->assertSame($before, $this->read($this->athlete)->headers->get('ETag'));
    }

    public function test_a_304_never_loads_raw(): void
    {
        $this->publish($this->athlete, 'prog-1');
        $this->publish($this->athlete, 'prog-2');
        $etag = $this->read($this->athlete)->headers->get('ETag');

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->read($this->athlete, ['If-None-Match' => $etag])->assertStatus(304);

        $coachQueries = array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'prism_coach_publications')));
        $this->assertNotEmpty($coachQueries, 'the ETag is computed from the table');
        foreach ($coachQueries as $sql) {
            $this->assertStringNotContainsString('raw', $sql, "a 304 loaded raw: {$sql}");
            $this->assertStringNotContainsString('*', $sql, "a 304 selected every column: {$sql}");
        }
    }

    public function test_a_coach_publication_never_appears_in_the_athlete_publications_feed(): void
    {
        $this->publish($this->athlete, 'prog-1');
        $athletePublication = new PrismAthletePublication([
            'gym_id' => 'the-cove',
            'program_uuid' => 'prog-team',
            'track' => 'peak',
            'start_date' => '2026-11-16',
            'raw' => json_encode(['metadata' => ['program' => 'Team'], 'workouts' => []]),
            'published_by_user_id' => $this->coach->id,
        ]);
        $athletePublication->published_at = now();
        $athletePublication->save();

        Sanctum::actingAs($this->coach, ['athlete-publications:read']);
        $response = $this->withHeaders(['X-Device-Id' => 'gh-action'])->getJson('/api/sync/athlete-publications');

        $response->assertOk()->assertJsonCount(1, 'publications');
        $this->assertSame(['prog-team'], array_column($response->json('publications'), 'program_uuid'));
    }

    public function test_guest_gets_401(): void
    {
        $this->withHeaders(['X-Device-Id' => 'dev-a'])->getJson('/api/sync/coach-programs')->assertStatus(401);
    }

    public function test_the_publications_read_token_is_refused(): void
    {
        $this->publish($this->coach, 'prog-1');
        $token = $this->coach->createToken('publications-read', ['athlete-publications:read'])->plainTextToken;

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'X-Device-Id' => 'dev-a'])
            ->getJson('/api/sync/coach-programs')
            ->assertStatus(403);
    }

    public function test_coach_programs_route_uses_the_athlete_sync_group(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route): bool => $route->uri() === 'api/sync/coach-programs' && in_array('GET', $route->methods(), true));

        $this->assertNotNull($route, 'Missing GET api/sync/coach-programs');
        $expected = ['auth:sanctum', \App\Sync\Middleware\EnsureFullAccessToken::class, 'throttle:sync-per-user', 'throttle:sync-global', 'device-id', 'log-sync-request'];
        foreach ($expected as $middleware) {
            $this->assertContains($middleware, $route->gatherMiddleware(), "coach-programs lacks {$middleware}");
        }
    }
}
