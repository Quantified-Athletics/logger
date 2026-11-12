<?php

namespace Tests\Feature\Sync\Prism;

use App\Models\Role;
use App\Models\User;
use App\Sync\Models\Gym;
use App\Sync\Models\PrismAthletePublication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The read-only publications token: it lists pending publications and can reach nothing else (issue #15, S2).
 */
class PrismAthletePublicationsReadTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Gym::create(['id' => 'the-cove', 'name' => 'The Cove']);
        $this->admin = User::factory()->create(['name' => 'Admin A', 'email' => 'admin@example.test']);
        $this->admin->roles()->attach(Role::firstOrCreate(['name' => 'Admin']));
    }

    /**
     * @return array<string, mixed>
     */
    private static function raw(string $program = 'Spring Block'): array
    {
        return [
            'metadata' => ['program' => $program, 'empty' => new \stdClass],
            'workouts' => [
                [
                    'id' => 'w1d1',
                    'week' => 1,
                    'day' => 1,
                    'text' => ['Back Squat 5x5'],
                    '_formatting' => ['formattedText' => ['**Back Squat** 5x5']],
                ],
            ],
        ];
    }

    private function publication(string $programUuid = 'prog-1', string $track = 'peak'): PrismAthletePublication
    {
        $publication = new PrismAthletePublication([
            'gym_id' => 'the-cove',
            'program_uuid' => $programUuid,
            'track' => $track,
            'start_date' => '2026-11-16',
            'raw' => json_encode(self::raw($programUuid)),
            'published_by_user_id' => $this->admin->id,
        ]);
        $publication->published_at = now();
        $publication->save();

        return $publication;
    }

    private function mintToken(string $email = 'admin@example.test'): string
    {
        $exitCode = Artisan::call('athlete:publications-token', ['email' => $email, '--no-interaction' => true]);
        $this->assertSame(0, $exitCode);
        $this->assertSame(1, preg_match('/^(\d+\|\S+)$/m', Artisan::output(), $matches), 'the command prints the token');

        return $matches[1];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function asToken(string $token, string $method, string $uri, array $body = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'X-Device-Id' => 'gh-action'])
            ->json($method, $uri, $body);
    }

    public function test_command_mints_a_token_whose_only_ability_is_reading_publications(): void
    {
        $token = $this->mintToken();

        $stored = PersonalAccessToken::findToken($token);
        $this->assertNotNull($stored);
        $this->assertSame(['athlete-publications:read'], $stored->abilities);
        $this->assertTrue($stored->tokenable->is($this->admin));
    }

    public function test_command_refuses_an_unknown_email_and_mints_nothing(): void
    {
        $exitCode = Artisan::call('athlete:publications-token', ['email' => 'nobody@example.test', '--no-interaction' => true]);

        $this->assertNotSame(0, $exitCode);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_minted_token_lists_publications_with_the_frozen_shape(): void
    {
        $publication = $this->publication();

        $response = $this->asToken($this->mintToken(), 'GET', '/api/sync/athlete-publications');

        $response->assertOk()->assertExactJson([
            'publications' => [[
                'id' => $publication->id,
                'gym_id' => 'the-cove',
                'program_uuid' => 'prog-1',
                'track' => 'peak',
                'start_date' => '2026-11-16',
                'raw' => json_decode(json_encode(self::raw('prog-1')), true),
                'published_by' => ['id' => $this->admin->id, 'name' => 'Admin A'],
                'published_at' => $publication->published_at->toIso8601String(),
            ]],
        ]);
    }

    public function test_published_blank_and_padded_lines_come_back_from_the_feed_unchanged(): void
    {
        $raw = self::raw();
        $raw['workouts'][0]['text'] = ['Back Squat 5x5', '', '  Row 3x10  ', ''];
        $raw['workouts'][0]['_formatting']['formattedText'] = ['**Back Squat** 5x5', '', '  Row 3x10  ', ''];
        \Laravel\Sanctum\Sanctum::actingAs($this->admin, ['*']);
        $this->withHeaders(['X-Device-Id' => 'dev-a'])
            ->postJson('/api/sync/prism/the-cove/programs/prog-1/athlete-publications', [
                'track' => 'peak', 'start_date' => '2026-11-16', 'raw' => $raw,
            ])
            ->assertStatus(201);

        $feed = $this->asToken($this->mintToken(), 'GET', '/api/sync/athlete-publications')->assertOk();

        $returned = $feed->json('publications.0.raw');
        $this->assertSame(json_decode(json_encode($raw), true), $returned);
        $this->assertSame(['Back Squat 5x5', '', '  Row 3x10  ', ''], $returned['workouts'][0]['text']);
        $this->assertSame(['**Back Squat** 5x5', '', '  Row 3x10  ', ''], $returned['workouts'][0]['_formatting']['formattedText']);
        $this->assertStringContainsString('"empty":{}', $feed->getContent());
    }

    public function test_raw_keeps_empty_objects_as_objects(): void
    {
        $this->publication();

        $content = $this->asToken($this->mintToken(), 'GET', '/api/sync/athlete-publications')->assertOk()->getContent();

        $this->assertStringContainsString('"empty":{}', $content);
    }

    public function test_publications_are_oldest_first_and_after_skips_handled_ones(): void
    {
        $first = $this->publication('prog-a');
        $second = $this->publication('prog-b', 'sa');
        $third = $this->publication('prog-a');
        $token = $this->mintToken();

        $all = $this->asToken($token, 'GET', '/api/sync/athlete-publications')->assertOk();
        $this->assertSame([$first->id, $second->id, $third->id], array_column($all->json('publications'), 'id'));

        $after = $this->asToken($token, 'GET', "/api/sync/athlete-publications?after={$first->id}")->assertOk();
        $this->assertSame([$second->id, $third->id], array_column($after->json('publications'), 'id'));

        $none = $this->asToken($token, 'GET', "/api/sync/athlete-publications?after={$third->id}")->assertOk();
        $this->assertSame([], $none->json('publications'));
    }

    public function test_at_most_twenty_publications_are_returned(): void
    {
        $ids = [];
        for ($i = 0; $i < 25; $i++) {
            $ids[] = $this->publication("prog-{$i}")->id;
        }
        $token = $this->mintToken();

        $page = $this->asToken($token, 'GET', '/api/sync/athlete-publications')->assertOk();
        $this->assertSame(array_slice($ids, 0, 20), array_column($page->json('publications'), 'id'));

        $rest = $this->asToken($token, 'GET', '/api/sync/athlete-publications?after='.$ids[19])->assertOk();
        $this->assertSame(array_slice($ids, 20), array_column($rest->json('publications'), 'id'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidAfterProvider(): array
    {
        return [
            'not a number' => ['abc'],
            'negative' => ['-1'],
            'fraction' => ['1.5'],
        ];
    }

    #[DataProvider('invalidAfterProvider')]
    public function test_invalid_after_is_refused_with_422(string $after): void
    {
        $this->asToken($this->mintToken(), 'GET', "/api/sync/athlete-publications?after={$after}")->assertStatus(422);
    }

    public function test_guest_gets_401_on_the_read_route(): void
    {
        $this->getJson('/api/sync/athlete-publications')->assertStatus(401);
    }

    public function test_normal_user_token_gets_403_on_the_read_route(): void
    {
        $this->publication();
        $normalToken = $this->admin->createToken('device-a')->plainTextToken;

        $this->asToken($normalToken, 'GET', '/api/sync/athlete-publications')
            ->assertStatus(403)
            ->assertJsonMissingPath('publications');
    }

    public function test_normal_user_token_still_reaches_the_other_routes(): void
    {
        $normalToken = $this->admin->createToken('device-a')->plainTextToken;

        $this->asToken($normalToken, 'GET', '/api/sync/me')->assertOk()->assertJson(['id' => $this->admin->id]);
        $this->asToken($normalToken, 'GET', '/api/sync/prism/the-cove/changes')->assertOk();
    }

    /**
     * Every other `auth:sanctum` route, one per middleware group and then some.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function otherRouteProvider(): array
    {
        return [
            'whoami' => ['GET', '/api/sync/me'],
            'lift log sync' => ['POST', '/api/sync/logs'],
            'lift log delete' => ['DELETE', '/api/sync/logs/1'],
            'lift log batch' => ['POST', '/api/sync/logs/batch'],
            'restore' => ['GET', '/api/sync/restore'],
            'changes' => ['GET', '/api/sync/changes'],
            'blueprint' => ['POST', '/api/sync/blueprint'],
            'preferences' => ['POST', '/api/sync/preferences'],
            'exercises' => ['POST', '/api/sync/exercises'],
            'prism program write' => ['POST', '/api/sync/prism/the-cove/programs/prog-1'],
            'prism cells write' => ['POST', '/api/sync/prism/the-cove/programs/prog-1/cells'],
            'prism destinations write' => ['POST', '/api/sync/prism/the-cove/programs/prog-1/destinations'],
            'athlete publication write' => ['POST', '/api/sync/prism/the-cove/programs/prog-1/athlete-publications'],
            'prism comments write' => ['POST', '/api/sync/prism/the-cove/programs/prog-1/comments'],
            'prism exercises write' => ['POST', '/api/sync/prism/the-cove/exercises'],
            'prism preferences write' => ['POST', '/api/sync/prism/the-cove/preferences'],
            'prism interference dismissals write' => ['POST', '/api/sync/prism/the-cove/interference-dismissals'],
            'prism exercise limits write' => ['POST', '/api/sync/prism/the-cove/exercise-limits'],
            'prism changes' => ['GET', '/api/sync/prism/the-cove/changes'],
        ];
    }

    #[DataProvider('otherRouteProvider')]
    public function test_read_only_token_gets_403_on_every_other_route(string $method, string $uri): void
    {
        $countBefore = PrismAthletePublication::count();

        $this->asToken($this->mintToken(), $method, $uri, [
            'track' => 'peak',
            'start_date' => '2026-11-16',
            'raw' => self::raw(),
        ])->assertStatus(403);

        $this->assertSame($countBefore, PrismAthletePublication::count());
    }
}
