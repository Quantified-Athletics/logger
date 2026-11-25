<?php

namespace Tests\Feature\Sync\Prism;

use App\Models\Role;
use App\Models\User;
use App\Sync\Models\Gym;
use App\Sync\Models\PrismCoachPublication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Coach publications: an Admin publishes a program to one athlete and revokes it (issue #21, S2).
 */
class PrismCoachPublicationsStoreTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $athlete;

    protected function setUp(): void
    {
        parent::setUp();
        Gym::create(['id' => 'the-cove', 'name' => 'The Cove']);
        $this->admin = User::factory()->create(['name' => 'Coach C']);
        $this->admin->roles()->attach(Role::firstOrCreate(['name' => 'Admin']));
        $this->athlete = User::factory()->create(['name' => 'Athlete A', 'email' => 'athlete@example.com']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function validRaw(string $programName = 'Spring Block'): array
    {
        return [
            'metadata' => [
                'source' => 'prism',
                'program_uuid' => 'prog-1',
                'program_name' => $programName,
                'track' => null,
                'start_date' => '2026-11-30',
                'published_at' => '2026-11-24T10:00:00+00:00',
            ],
            'workouts' => [
                [
                    'id' => 'w1d1',
                    'week' => 1,
                    'day' => 1,
                    'dateLabel' => 'Mon 30 Nov',
                    'isRest' => false,
                    'text' => ['Back Squat 5x5'],
                    '_formatting' => ['formattedText' => ['**Back Squat** 5x5']],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function validBody(string $email = 'athlete@example.com'): array
    {
        return ['recipient_email' => $email, 'start_date' => '2026-11-30', 'raw' => self::validRaw()];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function publish(array $body, string $programUuid = 'prog-1', string $gym = 'the-cove'): TestResponse
    {
        return $this->withHeaders(['X-Device-Id' => 'dev-a'])
            ->postJson("/api/sync/prism/{$gym}/programs/{$programUuid}/coach-publications", $body);
    }

    private function revoke(string $email = 'athlete@example.com', string $programUuid = 'prog-1', string $gym = 'the-cove'): TestResponse
    {
        return $this->withHeaders(['X-Device-Id' => 'dev-a'])
            ->postJson("/api/sync/prism/{$gym}/programs/{$programUuid}/coach-publications/revoke", ['recipient_email' => $email]);
    }

    public function test_admin_publishes_and_gets_201_with_the_recipient_name(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->publish(self::validBody());

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'published_at', 'recipient' => ['name']])
            ->assertJsonPath('recipient.name', 'Athlete A');

        $publication = PrismCoachPublication::firstOrFail();
        $this->assertSame($publication->id, $response->json('id'));
        $this->assertSame($publication->published_at->toIso8601String(), $response->json('published_at'));
        $this->assertSame('the-cove', $publication->gym_id);
        $this->assertSame('prog-1', $publication->program_uuid);
        $this->assertSame($this->athlete->id, $publication->recipient_user_id);
        $this->assertSame($this->admin->id, $publication->published_by_user_id);
        $this->assertSame('2026-11-30', $publication->start_date->toDateString());
        $this->assertNull($publication->revoked_at);
        $this->assertEquals(self::validRaw(), json_decode($publication->getRawOriginal('raw'), true));
    }

    public function test_raw_keeps_empty_objects_as_objects(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        $body = self::validBody();
        $body['raw']['workouts'][0]['_formatting'] = ['formattedText' => [], 'styles' => new \stdClass];

        $this->publish($body)->assertStatus(201);

        $stored = json_decode(PrismCoachPublication::firstOrFail()->getRawOriginal('raw'));
        $this->assertInstanceOf(\stdClass::class, $stored->workouts[0]->_formatting->styles);
    }

    public function test_publishing_again_is_a_new_row_and_leaves_the_first_alone(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $first = $this->publish(self::validBody())->assertStatus(201)->json('id');
        $body = self::validBody();
        $body['raw'] = self::validRaw('Spring Block v2');
        $second = $this->publish($body)->assertStatus(201)->json('id');

        $this->assertNotSame($first, $second);
        $this->assertDatabaseCount('prism_coach_publications', 2);
        $this->assertSame('Spring Block', json_decode(PrismCoachPublication::findOrFail($first)->raw, true)['metadata']['program_name']);
        $this->assertSame('Spring Block v2', json_decode(PrismCoachPublication::findOrFail($second)->raw, true)['metadata']['program_name']);
        $this->assertTrue(PrismCoachPublication::findOrFail($second)->published_at->gte(PrismCoachPublication::findOrFail($first)->published_at));
    }

    public function test_non_admin_cannot_publish(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->publish(self::validBody())->assertStatus(403);

        $this->assertDatabaseCount('prism_coach_publications', 0);
    }

    public function test_guest_cannot_publish(): void
    {
        $this->publish(self::validBody())->assertStatus(401);

        $this->assertDatabaseCount('prism_coach_publications', 0);
    }

    public function test_unknown_gym_is_404(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->publish(self::validBody(), gym: 'nowhere')->assertStatus(404);
        $this->revoke(gym: 'nowhere')->assertStatus(404);

        $this->assertDatabaseCount('prism_coach_publications', 0);
    }

    public function test_unknown_recipient_email_is_refused_and_no_account_is_created(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        $usersBefore = User::count();

        $this->publish(self::validBody('stranger@example.com'))
            ->assertStatus(422)
            ->assertExactJson(['error' => 'unknown_recipient']);

        $this->assertDatabaseCount('prism_coach_publications', 0);
        $this->assertSame($usersBefore, User::count());
    }

    public function test_a_soft_deleted_athlete_is_an_unknown_recipient(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        $this->athlete->delete();

        $this->publish(self::validBody())->assertStatus(422)->assertExactJson(['error' => 'unknown_recipient']);

        $this->assertDatabaseCount('prism_coach_publications', 0);
    }

    /**
     * @return array<string, array{0: callable(array<string, mixed>): array<string, mixed>}>
     */
    public static function malformedBodies(): array
    {
        return [
            'no recipient_email' => [fn (array $body): array => array_diff_key($body, ['recipient_email' => true])],
            'recipient_email not an email' => [fn (array $body): array => ['recipient_email' => 'not-an-email'] + $body],
            'no start_date' => [fn (array $body): array => array_diff_key($body, ['start_date' => true])],
            'start_date not Y-m-d' => [fn (array $body): array => ['start_date' => '30/11/2026'] + $body],
            'no raw' => [fn (array $body): array => array_diff_key($body, ['raw' => true])],
            'raw a string' => [fn (array $body): array => ['raw' => 'hello'] + $body],
            'raw a list' => [fn (array $body): array => ['raw' => [1, 2, 3]] + $body],
            'raw without metadata' => [fn (array $body): array => ['raw' => ['workouts' => $body['raw']['workouts']]] + $body],
            'raw metadata a list' => [fn (array $body): array => ['raw' => ['metadata' => ['a', 'b'], 'workouts' => $body['raw']['workouts']]] + $body],
            'raw metadata a string' => [fn (array $body): array => ['raw' => ['metadata' => 'x', 'workouts' => $body['raw']['workouts']]] + $body],
            'raw without workouts' => [fn (array $body): array => ['raw' => ['metadata' => $body['raw']['metadata']]] + $body],
            'raw workouts an object' => [fn (array $body): array => ['raw' => ['metadata' => $body['raw']['metadata'], 'workouts' => ['a' => 1]]] + $body],
            'raw workouts a string' => [fn (array $body): array => ['raw' => ['metadata' => $body['raw']['metadata'], 'workouts' => 'x']] + $body],
        ];
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     */
    #[DataProvider('malformedBodies')]
    public function test_malformed_body_is_422_and_nothing_is_stored(callable $mutate): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->publish($mutate(self::validBody()))->assertStatus(422);

        $this->assertDatabaseCount('prism_coach_publications', 0);
    }

    public function test_raw_metadata_as_an_empty_json_list_is_refused(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        $body = self::validBody();
        $body['raw'] = ['metadata' => [], 'workouts' => $body['raw']['workouts']];

        $this->publish($body)->assertStatus(422);

        $this->assertDatabaseCount('prism_coach_publications', 0);
    }

    public function test_revoke_marks_every_publication_of_that_program_to_that_athlete(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        $this->publish(self::validBody())->assertStatus(201);
        $this->publish(self::validBody())->assertStatus(201);

        $this->revoke()->assertStatus(200)->assertExactJson(['revoked' => 2]);

        $this->assertSame(0, PrismCoachPublication::query()->whereNull('revoked_at')->count());
    }

    public function test_revoke_leaves_other_programs_other_athletes_and_other_gyms_alone(): void
    {
        Gym::create(['id' => 'other-gym', 'name' => 'Other Gym']);
        $otherAthlete = User::factory()->create(['email' => 'other@example.com']);
        Sanctum::actingAs($this->admin, ['*']);

        $target = $this->publish(self::validBody())->assertStatus(201)->json('id');
        $otherProgram = $this->publish(self::validBody(), programUuid: 'prog-2')->assertStatus(201)->json('id');
        $otherRecipient = $this->publish(self::validBody('other@example.com'))->assertStatus(201)->json('id');
        $otherGym = $this->publish(self::validBody(), gym: 'other-gym')->assertStatus(201)->json('id');

        $this->revoke()->assertStatus(200)->assertExactJson(['revoked' => 1]);

        $this->assertNotNull(PrismCoachPublication::findOrFail($target)->revoked_at);
        $this->assertNull(PrismCoachPublication::findOrFail($otherProgram)->revoked_at);
        $this->assertNull(PrismCoachPublication::findOrFail($otherRecipient)->revoked_at);
        $this->assertNull(PrismCoachPublication::findOrFail($otherGym)->revoked_at);
        $this->assertSame($otherAthlete->id, PrismCoachPublication::findOrFail($otherRecipient)->recipient_user_id);
    }

    public function test_revoke_with_nothing_to_revoke_is_200_with_zero(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->revoke()->assertStatus(200)->assertExactJson(['revoked' => 0]);
    }

    public function test_revoking_twice_counts_only_what_it_changed_and_keeps_the_first_revoked_at(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        $id = $this->publish(self::validBody())->assertStatus(201)->json('id');
        $this->revoke()->assertExactJson(['revoked' => 1]);
        $firstRevokedAt = PrismCoachPublication::findOrFail($id)->getRawOriginal('revoked_at');

        $this->travel(5)->minutes();
        $this->revoke()->assertStatus(200)->assertExactJson(['revoked' => 0]);

        $this->assertSame($firstRevokedAt, PrismCoachPublication::findOrFail($id)->getRawOriginal('revoked_at'));
    }

    public function test_publishing_after_a_revoke_stores_a_live_publication(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        $first = $this->publish(self::validBody())->assertStatus(201)->json('id');
        $this->revoke()->assertExactJson(['revoked' => 1]);

        $second = $this->publish(self::validBody())->assertStatus(201)->json('id');

        $this->assertNotNull(PrismCoachPublication::findOrFail($first)->revoked_at);
        $this->assertNull(PrismCoachPublication::findOrFail($second)->revoked_at);
        $this->assertTrue(PrismCoachPublication::findOrFail($second)->published_at->gte(PrismCoachPublication::findOrFail($first)->revoked_at));
    }

    public function test_non_admin_cannot_revoke(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        $id = $this->publish(self::validBody())->assertStatus(201)->json('id');

        Sanctum::actingAs($this->athlete, ['*']);
        $this->revoke()->assertStatus(403);

        $this->assertNull(PrismCoachPublication::findOrFail($id)->revoked_at);
    }

    public function test_guest_cannot_revoke(): void
    {
        $this->revoke()->assertStatus(401);
    }

    public function test_revoke_of_an_unknown_email_is_unknown_recipient(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->revoke('stranger@example.com')->assertStatus(422)->assertExactJson(['error' => 'unknown_recipient']);
    }

    public function test_revoke_without_an_email_is_422(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders(['X-Device-Id' => 'dev-a'])
            ->postJson('/api/sync/prism/the-cove/programs/prog-1/coach-publications/revoke', [])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    public function test_coach_publication_routes_use_the_prism_write_group(): void
    {
        $expected = ['auth:sanctum', \App\Sync\Middleware\EnsureFullAccessToken::class, 'throttle:prism-write', 'device-id', 'log-sync-request'];

        foreach (['api/sync/prism/{gym}/programs/{program_uuid}/coach-publications', 'api/sync/prism/{gym}/programs/{program_uuid}/coach-publications/revoke'] as $uri) {
            $route = collect(app('router')->getRoutes()->getRoutes())
                ->first(fn ($route): bool => $route->uri() === $uri && in_array('POST', $route->methods(), true));

            $this->assertNotNull($route, "Missing POST {$uri}");
            foreach ($expected as $middleware) {
                $this->assertContains($middleware, $route->gatherMiddleware(), "{$uri} lacks {$middleware}");
            }
        }
    }
}
