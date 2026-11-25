<?php

namespace Tests\Feature\Sync\Prism;

use App\Models\User;
use App\Sync\Models\Gym;
use App\Sync\Models\PrismCoachPublication;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Coach publications: a Prism program snapshot sent to one athlete is stored (issue #21, S1).
 */
class PrismCoachPublicationTableTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_11_24_130000_create_prism_coach_publications_table.php';

    public function test_table_has_the_frozen_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('prism_coach_publications', [
            'id', 'gym_id', 'program_uuid', 'recipient_user_id', 'start_date', 'raw',
            'published_by_user_id', 'published_at', 'revoked_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_recipient_program_published_at_index_exists(): void
    {
        $indexedColumns = array_map(
            fn (array $index): array => $index['columns'],
            Schema::getIndexes('prism_coach_publications'),
        );

        $this->assertContains(['recipient_user_id', 'program_uuid', 'published_at'], $indexedColumns);
    }

    /** MySQL refuses a longer identifier, and SQLite, which these tests run on, does not. */
    public function test_index_names_fit_the_mysql_identifier_limit(): void
    {
        foreach (Schema::getIndexes('prism_coach_publications') as $index) {
            $this->assertLessThanOrEqual(64, strlen($index['name']), "{$index['name']} is too long for MySQL");
        }
    }

    public function test_factory_stores_a_current_publication_with_its_relations(): void
    {
        $publication = PrismCoachPublication::factory()->create();

        $publication->refresh();
        $this->assertNull($publication->revoked_at);
        $this->assertInstanceOf(User::class, $publication->recipient);
        $this->assertInstanceOf(User::class, $publication->publishedBy);
        $this->assertInstanceOf(Gym::class, $publication->gym);
        $this->assertNotSame($publication->recipient->id, $publication->publishedBy->id);
        $this->assertIsArray(json_decode($publication->raw, true)['workouts']);
    }

    public function test_raw_is_stored_byte_for_byte(): void
    {
        $raw = '{"workouts":[],"metadata":{"program_name":"Spring Block","source":"prism"}}';

        $publication = PrismCoachPublication::factory()->create(['raw' => $raw]);

        $this->assertSame($raw, $publication->fresh()->raw);
    }

    public function test_revoked_state_sets_revoked_at(): void
    {
        $publication = PrismCoachPublication::factory()->revoked()->create();

        $this->assertNotNull($publication->fresh()->revoked_at);
    }

    public function test_published_at_keeps_microseconds(): void
    {
        $publication = PrismCoachPublication::factory()->create([
            'published_at' => '2026-11-24 10:00:00.123456',
        ]);

        $this->assertSame('123456', $publication->fresh()->published_at->format('u'));
    }

    public function test_recipient_must_be_an_existing_user(): void
    {
        $this->expectException(QueryException::class);

        PrismCoachPublication::factory()->create(['recipient_user_id' => 999999]);
    }

    public function test_deleting_the_recipient_deletes_their_publications(): void
    {
        $publication = PrismCoachPublication::factory()->create();
        $other = PrismCoachPublication::factory()->create();

        $publication->recipient->forceDelete();

        $this->assertDatabaseMissing('prism_coach_publications', ['id' => $publication->id]);
        $this->assertDatabaseHas('prism_coach_publications', ['id' => $other->id]);
    }

    public function test_deleting_the_coach_keeps_the_publication_without_a_publisher(): void
    {
        $publication = PrismCoachPublication::factory()->create();

        $publication->publishedBy->forceDelete();

        $this->assertDatabaseHas('prism_coach_publications', [
            'id' => $publication->id,
            'published_by_user_id' => null,
        ]);
    }

    public function test_deleting_the_gym_deletes_its_publications(): void
    {
        $publication = PrismCoachPublication::factory()->create();

        $publication->gym->delete();

        $this->assertDatabaseMissing('prism_coach_publications', ['id' => $publication->id]);
    }

    public function test_athlete_publications_table_is_untouched(): void
    {
        $this->assertFalse(Schema::hasColumn('prism_athlete_publications', 'recipient_user_id'));
        $this->assertFalse(Schema::hasColumn('prism_athlete_publications', 'revoked_at'));
    }

    public function test_migration_runs_down_and_up(): void
    {
        /** @var Migration $migration */
        $migration = require database_path(self::MIGRATION);

        $migration->down();
        $this->assertFalse(Schema::hasTable('prism_coach_publications'));
        $this->assertTrue(Schema::hasTable('prism_athlete_publications'));

        $migration->up();
        $this->assertTrue(Schema::hasColumns('prism_coach_publications', [
            'recipient_user_id', 'revoked_at',
        ]));
    }
}
