<?php

namespace Tests\Feature\Sync\Prism;

use App\Sync\Models\Gym;
use App\Sync\Models\PrismAthletePublication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Athlete reads a gym's current track programs without sign-in (issue #38).
 */
class TrackProgramsReadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-27 12:00:00');
        Gym::create(['id' => 'the-cove', 'name' => 'The Cove']);
        Gym::create(['id' => 'other-gym', 'name' => 'Other Gym']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function raw(string $programName): array
    {
        return [
            'metadata' => ['program_name' => $programName, 'track' => 'peak', 'empty' => new \stdClass],
            'workouts' => [
                [
                    'id' => 'w1d1',
                    'text' => ["{$programName} day 1"],
                    '_formatting' => ['formattedText' => ["**{$programName}** day 1"]],
                ],
            ],
        ];
    }

    private function publish(
        string $programUuid,
        string $programName = 'Peak EOY',
        string $startDate = '2026-11-30',
        ?string $publishedAt = null,
        string $track = 'peak',
        string $gym = 'the-cove',
        ?string $rawJson = null,
    ): PrismAthletePublication {
        $publication = new PrismAthletePublication([
            'gym_id' => $gym,
            'program_uuid' => $programUuid,
            'track' => $track,
            'start_date' => $startDate,
            'raw' => $rawJson ?? json_encode(self::raw($programName)),
        ]);
        $publication->published_at = $publishedAt ? Carbon::parse($publishedAt) : now();
        $publication->save();

        return $publication;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function read(string $gym = 'the-cove', array $headers = []): TestResponse
    {
        return $this->withHeaders(['X-Device-Id' => 'dev-a'] + $headers)->getJson("/api/sync/gyms/{$gym}/track-programs");
    }

    /**
     * @return list<int>
     */
    private function publicationIds(TestResponse $response): array
    {
        return array_column($response->json('programs'), 'publication_id');
    }

    public function test_returns_the_current_publication_with_its_shape_and_raw_unchanged(): void
    {
        $rawJson = '{"metadata":{"program_name":"Peak EOY","empty":{}},"workouts":[{"id":"w1d1","_formatting":{"formattedText":["**x**"]}}]}';
        $publication = $this->publish('prog-1', startDate: '2026-11-30', rawJson: $rawJson);

        $response = $this->read();

        $response->assertOk()->assertExactJson([
            'programs' => [[
                'publication_id' => $publication->id,
                'program_uuid' => 'prog-1',
                'program_name' => 'Peak EOY',
                'track' => 'peak',
                'start_date' => '2026-11-30',
                'published_at' => $publication->published_at->toIso8601String(),
                'raw' => json_decode($rawJson, true),
            ]],
        ]);
        $body = json_decode($response->getContent());
        $this->assertEquals(json_decode($rawJson), $body->programs[0]->raw);
        $this->assertInstanceOf(\stdClass::class, $body->programs[0]->raw->metadata->empty);
        $this->assertNotEmpty($response->headers->get('ETag'));
    }

    public function test_program_name_is_null_when_absent(): void
    {
        $this->publish('prog-1', rawJson: '{"metadata":{},"workouts":[]}');

        $this->read()->assertOk()->assertJsonPath('programs.0.program_name', null);
    }

    public function test_returns_the_newest_per_program_drops_superseded_stale_and_other_gyms(): void
    {
        $this->publish('prog-1', 'Old', '2026-11-30', '2026-11-20 10:00:00');
        $revision = $this->publish('prog-1', 'New', '2026-11-30', '2026-11-21 10:00:00');
        $edge = $this->publish('prog-edge', 'Edge', '2026-09-04', '2026-09-01 10:00:00');
        $this->publish('prog-stale', 'Stale', '2026-09-03', '2026-09-01 10:00:00');
        $this->publish('prog-other', 'Other', '2026-11-30', gym: 'other-gym');

        $response = $this->read();

        $this->assertEqualsCanonicalizing([$revision->id, $edge->id], $this->publicationIds($response));
        $this->assertSame('New', collect($response->json('programs'))->firstWhere('publication_id', $revision->id)['program_name']);
    }

    public function test_a_stale_revision_drops_the_program_without_falling_back(): void
    {
        $this->publish('prog-1', 'Recent', '2026-11-30', '2026-11-20 10:00:00');
        $this->publish('prog-1', 'Moved back', '2026-08-01', '2026-11-21 10:00:00');

        $this->assertSame([], $this->publicationIds($this->read()));
    }

    public function test_the_latest_by_published_at_wins_and_a_tie_goes_to_the_higher_id(): void
    {
        $latest = $this->publish('prog-1', 'Newest', '2026-11-30', '2026-11-22 10:00:00');
        $this->publish('prog-1', 'Older', '2026-11-30', '2026-11-21 10:00:00');
        $this->publish('prog-2', 'First', '2026-11-30', '2026-11-22 10:00:00');
        $second = $this->publish('prog-2', 'Second', '2026-11-30', '2026-11-22 10:00:00');

        $this->assertEqualsCanonicalizing([$latest->id, $second->id], $this->publicationIds($this->read()));
    }

    public function test_programs_are_ordered_by_track_then_start_date_then_id(): void
    {
        $saLate = $this->publish('sa-late', startDate: '2026-12-07', track: 'sa');
        $peakLate = $this->publish('peak-late', startDate: '2026-12-07');
        $peakEarlyB = $this->publish('peak-early-b', startDate: '2026-11-30');
        $saEarly = $this->publish('sa-early', startDate: '2026-11-30', track: 'sa');
        $peakEarlyA = $this->publish('peak-early-a', startDate: '2026-11-30');

        $this->assertSame(
            [$peakEarlyB->id, $peakEarlyA->id, $peakLate->id, $saEarly->id, $saLate->id],
            $this->publicationIds($this->read()),
        );
    }

    public function test_no_authorization_header_is_needed(): void
    {
        $this->publish('prog-1');

        $response = $this->getJson('/api/sync/gyms/the-cove/track-programs');

        $response->assertOk()->assertJsonCount(1, 'programs');
    }

    public function test_an_unknown_gym_is_404(): void
    {
        $this->read('no-such-gym')->assertNotFound();
    }

    public function test_a_gym_with_nothing_gets_an_empty_list(): void
    {
        $this->read()->assertOk()->assertExactJson(['programs' => []]);
    }

    public function test_a_matching_if_none_match_is_304_with_no_body(): void
    {
        $this->publish('prog-1');
        $etag = $this->read()->headers->get('ETag');

        $response = $this->read(headers: ['If-None-Match' => $etag]);

        $response->assertStatus(304);
        $this->assertSame('', $response->getContent());
        $this->assertSame($etag, $response->headers->get('ETag'));
    }

    public function test_a_new_publish_changes_the_etag(): void
    {
        $this->publish('prog-1', publishedAt: '2026-11-20 10:00:00');
        $before = $this->read()->headers->get('ETag');

        $revision = $this->publish('prog-1', 'Peak EOY v2', publishedAt: '2026-11-21 10:00:00');
        $response = $this->read(headers: ['If-None-Match' => $before]);

        $response->assertOk();
        $this->assertNotSame($before, $response->headers->get('ETag'));
        $this->assertSame([$revision->id], $this->publicationIds($response));
    }

    public function test_the_etag_differs_per_gym(): void
    {
        $this->assertNotSame(
            $this->read('the-cove')->headers->get('ETag'),
            $this->read('other-gym')->headers->get('ETag'),
        );
    }

    public function test_a_second_read_of_the_same_etag_comes_from_the_cache(): void
    {
        $this->publish('prog-1');
        $this->publish('prog-2', track: 'sa');
        $first = $this->read();

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $second = $this->read();

        $second->assertOk();
        $this->assertSame($first->getContent(), $second->getContent());
        $publicationQueries = array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'prism_athlete_publications')));
        $this->assertNotEmpty($publicationQueries, 'the ETag is computed from the table');
        foreach ($publicationQueries as $sql) {
            $this->assertStringNotContainsString('raw', $sql, "a cached read loaded raw: {$sql}");
            $this->assertStringNotContainsString('*', $sql, "a cached read selected every column: {$sql}");
        }
    }

    public function test_a_304_never_loads_raw(): void
    {
        $this->publish('prog-1');
        $etag = $this->read()->headers->get('ETag');

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->read(headers: ['If-None-Match' => $etag])->assertStatus(304);

        $publicationQueries = array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'prism_athlete_publications')));
        $this->assertNotEmpty($publicationQueries, 'the ETag is computed from the table');
        foreach ($publicationQueries as $sql) {
            $this->assertStringNotContainsString('raw', $sql, "a 304 loaded raw: {$sql}");
            $this->assertStringNotContainsString('*', $sql, "a 304 selected every column: {$sql}");
        }
    }

    public function test_the_throttle_keys_by_device_not_ip(): void
    {
        config(['rate_limits.track_programs.per_minute' => 2]);

        foreach (['dev-a', 'dev-b'] as $device) {
            for ($request = 0; $request < 2; $request++) {
                $this->withHeaders(['X-Device-Id' => $device])
                    ->getJson('/api/sync/gyms/the-cove/track-programs')
                    ->assertOk();
            }
        }

        $this->withHeaders(['X-Device-Id' => 'dev-a'])->getJson('/api/sync/gyms/the-cove/track-programs')->assertStatus(429);
        $this->withHeaders(['X-Device-Id' => 'dev-c'])->getJson('/api/sync/gyms/the-cove/track-programs')->assertOk();
    }

    public function test_the_limits_are_configured(): void
    {
        $this->assertSame(30, config('rate_limits.track_programs.per_minute'));
        $this->assertSame(600, config('rate_limits.track_programs.per_hour'));
    }

    public function test_the_route_takes_no_sign_in_and_no_request_logging(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route): bool => $route->uri() === 'api/sync/gyms/{gym}/track-programs' && in_array('GET', $route->methods(), true));

        $this->assertNotNull($route, 'Missing GET api/sync/gyms/{gym}/track-programs');
        $middleware = $route->gatherMiddleware();
        $this->assertContains('device-id', $middleware);
        $this->assertContains('throttle:track-programs', $middleware);
        $this->assertNotContains('auth:sanctum', $middleware);
        $this->assertNotContains('log-sync-request', $middleware);
    }
}
