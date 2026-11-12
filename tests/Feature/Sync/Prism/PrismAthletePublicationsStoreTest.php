<?php

namespace Tests\Feature\Sync\Prism;

use App\Models\Role;
use App\Models\User;
use App\Sync\Models\Gym;
use App\Sync\Models\PrismAthletePublication;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * "Publish to Athlete" publications: an admin stores one, nobody else can (issue #15, S1).
 */
class PrismAthletePublicationsStoreTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Gym::create(['id' => 'the-cove', 'name' => 'The Cove']);
        $this->admin = User::factory()->create(['name' => 'Admin A']);
        $this->admin->roles()->attach(Role::firstOrCreate(['name' => 'Admin']));
    }

    /**
     * @return array<string, mixed>
     */
    private static function validRaw(): array
    {
        return [
            'metadata' => ['program' => 'Spring Block', 'startDate' => '2026-11-16'],
            'workouts' => [
                [
                    'id' => 'w1d1',
                    'week' => 1,
                    'day' => 1,
                    'text' => ['Back Squat 5x5', 'Row 3x10'],
                    '_formatting' => ['formattedText' => ['**Back Squat** 5x5', 'Row 3x10']],
                ],
                [
                    'id' => 'w1d2',
                    'week' => 1,
                    'day' => 2,
                    'text' => ['Bench 5x5'],
                    '_formatting' => ['formattedText' => ['**Bench** 5x5']],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function publish(array $body, string $programUuid = 'prog-1', string $gym = 'the-cove'): TestResponse
    {
        return $this->withHeaders(['X-Device-Id' => 'dev-a'])
            ->postJson("/api/sync/prism/{$gym}/programs/{$programUuid}/athlete-publications", $body);
    }

    /**
     * @return array<string, mixed>
     */
    private static function validBody(): array
    {
        return ['track' => 'peak', 'start_date' => '2026-11-16', 'raw' => self::validRaw()];
    }

    public function test_admin_publication_is_stored_and_returns_201(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->publish(self::validBody());

        $response->assertStatus(201)->assertJsonStructure(['id', 'published_at']);

        $publication = PrismAthletePublication::firstOrFail();
        $this->assertSame($publication->id, $response->json('id'));
        $this->assertSame($publication->published_at->toIso8601String(), $response->json('published_at'));
        $this->assertSame('the-cove', $publication->gym_id);
        $this->assertSame('prog-1', $publication->program_uuid);
        $this->assertSame('peak', $publication->track);
        $this->assertSame('2026-11-16', $publication->start_date->toDateString());
        $this->assertSame($this->admin->id, $publication->published_by_user_id);
        $this->assertEquals(self::validRaw(), json_decode($publication->getRawOriginal('raw'), true));
    }

    public function test_raw_keeps_empty_objects_as_objects(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        $raw = self::validRaw();
        $raw['metadata'] = new \stdClass;
        $raw['workouts'][0]['_formatting'] = ['formattedText' => [], 'styles' => new \stdClass];

        $this->publish(['track' => 'sa', 'start_date' => '2026-11-16', 'raw' => $raw])->assertStatus(201);

        $stored = json_decode(PrismAthletePublication::firstOrFail()->getRawOriginal('raw'));
        $this->assertInstanceOf(\stdClass::class, $stored->metadata);
        $this->assertInstanceOf(\stdClass::class, $stored->workouts[0]->_formatting->styles);
    }

    public function test_non_admin_gets_403_and_nothing_is_stored(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->publish(self::validBody())->assertStatus(403);

        $this->assertDatabaseCount('prism_athlete_publications', 0);
    }

    public function test_guest_gets_401(): void
    {
        $this->publish(self::validBody())->assertStatus(401);

        $this->assertDatabaseCount('prism_athlete_publications', 0);
    }

    public function test_unknown_gym_gets_404(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->publish(self::validBody(), gym: 'nowhere')->assertStatus(404);

        $this->assertDatabaseCount('prism_athlete_publications', 0);
    }

    public function test_second_publication_of_the_same_program_is_a_new_row(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $first = $this->publish(self::validBody())->assertStatus(201)->json('id');
        $second = $this->publish(['track' => 'sa'] + self::validBody())->assertStatus(201)->json('id');

        $this->assertNotSame($first, $second);
        $this->assertDatabaseCount('prism_athlete_publications', 2);
        $this->assertSame(['peak', 'sa'], PrismAthletePublication::orderBy('id')->pluck('track')->all());
    }

    /**
     * @return array<string, array{0: callable(array<string, mixed>): array<string, mixed>}>
     */
    public static function invalidBodies(): array
    {
        $workout = function (array $body, string $key, mixed $value): array {
            data_set($body, "raw.workouts.0.{$key}", $value);

            return $body;
        };
        $without = function (array $body, string $path): array {
            \Illuminate\Support\Arr::forget($body, $path);

            return $body;
        };

        return [
            'track missing' => [fn (array $b) => $without($b, 'track')],
            'track unknown' => [fn (array $b) => ['track' => 'bridge'] + $b],
            'start_date missing' => [fn (array $b) => $without($b, 'start_date')],
            'start_date not a date' => [fn (array $b) => ['start_date' => '16/11/2026'] + $b],
            'raw missing' => [fn (array $b) => $without($b, 'raw')],
            'raw a string' => [fn (array $b) => ['raw' => 'not json'] + $b],
            'raw a list' => [fn (array $b) => ['raw' => [self::validRaw()['workouts'][0]]] + $b],
            'metadata missing' => [fn (array $b) => $without($b, 'raw.metadata')],
            'metadata a string' => [function (array $b) {
                $b['raw']['metadata'] = 'x';

                return $b;
            }],
            'metadata a list' => [function (array $b) {
                $b['raw']['metadata'] = ['a', 'b'];

                return $b;
            }],
            'workouts missing' => [fn (array $b) => $without($b, 'raw.workouts')],
            'workouts empty' => [function (array $b) {
                $b['raw']['workouts'] = [];

                return $b;
            }],
            'workouts an object' => [function (array $b) {
                $b['raw']['workouts'] = ['first' => $b['raw']['workouts'][0]];

                return $b;
            }],
            'workout not an object' => [function (array $b) {
                $b['raw']['workouts'][0] = 'w1d1';

                return $b;
            }],
            'id missing' => [fn (array $b) => $without($b, 'raw.workouts.0.id')],
            'id malformed' => [fn (array $b) => $workout($b, 'id', 'week1day1')],
            'id with suffix' => [fn (array $b) => $workout($b, 'id', 'w1d1x')],
            'week missing' => [fn (array $b) => $without($b, 'raw.workouts.0.week')],
            'week not an integer' => [fn (array $b) => $workout($b, 'week', 'one')],
            'day missing' => [fn (array $b) => $without($b, 'raw.workouts.0.day')],
            'day not an integer' => [fn (array $b) => $workout($b, 'day', 'one')],
            'text missing' => [fn (array $b) => $without($b, 'raw.workouts.0.text')],
            'text a string' => [fn (array $b) => $workout($b, 'text', 'Back Squat 5x5')],
            'text holds a non-string' => [fn (array $b) => $workout($b, 'text', ['ok', 5])],
            '_formatting missing' => [fn (array $b) => $without($b, 'raw.workouts.0._formatting')],
            'formattedText missing' => [fn (array $b) => $without($b, 'raw.workouts.0._formatting.formattedText')],
            'formattedText a string' => [fn (array $b) => $workout($b, '_formatting.formattedText', 'x')],
            'formattedText holds a non-string' => [fn (array $b) => $workout($b, '_formatting.formattedText', [['nested']])],
            'second workout broken' => [function (array $b) {
                unset($b['raw']['workouts'][1]['text']);

                return $b;
            }],
        ];
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     */
    #[DataProvider('invalidBodies')]
    public function test_each_shape_rule_refuses_with_422_and_stores_nothing(callable $mutate): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->publish($mutate(self::validBody()))->assertStatus(422);

        $this->assertDatabaseCount('prism_athlete_publications', 0);
    }

    public function test_raw_over_two_megabytes_is_refused(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        $body = self::validBody();
        $body['raw']['metadata']['notes'] = str_repeat('x', 2 * 1024 * 1024);

        $this->publish($body)->assertStatus(422);

        $this->assertDatabaseCount('prism_athlete_publications', 0);
    }

    public function test_migration_runs_down_and_up(): void
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_11_12_120000_create_prism_athlete_publications_table.php');

        $this->assertTrue(Schema::hasTable('prism_athlete_publications'));

        $migration->down();
        $this->assertFalse(Schema::hasTable('prism_athlete_publications'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('prism_athlete_publications'));
        $this->assertTrue(Schema::hasColumns('prism_athlete_publications', [
            'id', 'gym_id', 'program_uuid', 'track', 'start_date', 'raw',
            'published_by_user_id', 'published_at', 'created_at', 'updated_at',
        ]));
    }
}
