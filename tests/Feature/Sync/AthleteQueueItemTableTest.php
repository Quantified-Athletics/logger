<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use App\Sync\Models\AthleteQueueItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Queue sync: an athlete's workout-queue items are stored opaquely, with tombstones (issue #23, S1).
 */
class AthleteQueueItemTableTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_11_24_140000_create_athlete_queue_items_table.php';

    public function test_table_has_the_frozen_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('athlete_queue_items', [
            'id', 'user_id', 'item_id', 'item', 'item_updated_at', 'deleted_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_user_and_item_id_are_unique_together(): void
    {
        $uniqueColumns = array_map(
            fn (array $index): array => $index['columns'],
            array_filter(Schema::getIndexes('athlete_queue_items'), fn (array $index): bool => $index['unique'] && ! $index['primary']),
        );

        $this->assertContains(['user_id', 'item_id'], $uniqueColumns);
    }

    public function test_a_second_row_for_the_same_athlete_and_item_is_refused(): void
    {
        $item = AthleteQueueItem::factory()->create();

        $this->expectException(QueryException::class);

        AthleteQueueItem::factory()->create(['user_id' => $item->user_id, 'item_id' => $item->item_id]);
    }

    public function test_two_athletes_may_hold_the_same_item_id(): void
    {
        $first = AthleteQueueItem::factory()->create(['item_id' => 'q-shared']);
        $second = AthleteQueueItem::factory()->create(['item_id' => 'q-shared']);

        $this->assertNotSame($first->user_id, $second->user_id);
        $this->assertSame(2, AthleteQueueItem::query()->where('item_id', 'q-shared')->count());
    }

    /** MySQL refuses a longer identifier, and SQLite, which these tests run on, does not. */
    public function test_index_names_fit_the_mysql_identifier_limit(): void
    {
        foreach (Schema::getIndexes('athlete_queue_items') as $index) {
            $this->assertLessThanOrEqual(64, strlen($index['name']), "{$index['name']} is too long for MySQL");
        }
        foreach (Schema::getForeignKeys('athlete_queue_items') as $foreignKey) {
            $this->assertLessThanOrEqual(64, strlen((string) $foreignKey['name']), "{$foreignKey['name']} is too long for MySQL");
        }
    }

    public function test_factory_stores_a_live_item_as_its_json(): void
    {
        $queueItem = AthleteQueueItem::factory()->create()->fresh();

        $this->assertNull($queueItem->deleted_at);
        $this->assertInstanceOf(User::class, $queueItem->user);
        $decoded = json_decode($queueItem->item, true);
        $this->assertSame($queueItem->item_id, $decoded['id']);
        $this->assertNotSame('coach', $decoded['source']);
        $this->assertSame(
            $queueItem->item_updated_at->format('Y-m-d H:i:s.u'),
            \Carbon\CarbonImmutable::parse($decoded['updatedAt'])->utc()->format('Y-m-d H:i:s.u'),
        );
    }

    public function test_item_is_stored_byte_for_byte(): void
    {
        $item = '{"id":"q-1","updatedAt":"2026-11-24T10:00:00.123Z","source":"generated","blocks":[],"meta":{},"generatorConfig":{"x":[1,{"y":null}]}}';

        $queueItem = AthleteQueueItem::factory()->create(['item_id' => 'q-1', 'item' => $item]);

        $this->assertSame($item, $queueItem->fresh()->item);
    }

    public function test_tombstoned_state_sets_deleted_at(): void
    {
        $queueItem = AthleteQueueItem::factory()->tombstoned()->create();

        $this->assertNotNull($queueItem->fresh()->deleted_at);
    }

    public function test_live_and_tombstoned_scopes_split_on_deleted_at(): void
    {
        $live = AthleteQueueItem::factory()->create();
        $tombstoned = AthleteQueueItem::factory()->tombstoned()->create();

        $this->assertSame([$live->id], AthleteQueueItem::query()->live()->pluck('id')->all());
        $this->assertSame([$tombstoned->id], AthleteQueueItem::query()->tombstoned()->pluck('id')->all());
    }

    public function test_timestamps_keep_microseconds(): void
    {
        $queueItem = AthleteQueueItem::factory()->create([
            'item_updated_at' => '2026-11-24 10:00:00.123456',
            'deleted_at' => '2026-11-24 11:00:00.654321',
        ])->fresh();

        $this->assertSame('123456', $queueItem->item_updated_at->format('u'));
        $this->assertSame('654321', $queueItem->deleted_at->format('u'));
    }

    public function test_user_must_be_an_existing_user(): void
    {
        $this->expectException(QueryException::class);

        AthleteQueueItem::factory()->create(['user_id' => 999999]);
    }

    public function test_deleting_the_athlete_deletes_their_items(): void
    {
        $queueItem = AthleteQueueItem::factory()->create();
        $tombstone = AthleteQueueItem::factory()->tombstoned()->create(['user_id' => $queueItem->user_id]);
        $other = AthleteQueueItem::factory()->create();

        $queueItem->user->forceDelete();

        $this->assertDatabaseMissing('athlete_queue_items', ['id' => $queueItem->id]);
        $this->assertDatabaseMissing('athlete_queue_items', ['id' => $tombstone->id]);
        $this->assertDatabaseHas('athlete_queue_items', ['id' => $other->id]);
    }

    public function test_migration_runs_down_and_up(): void
    {
        /** @var Migration $migration */
        $migration = require database_path(self::MIGRATION);

        $migration->down();
        $this->assertFalse(Schema::hasTable('athlete_queue_items'));
        $this->assertTrue(Schema::hasTable('users'));

        $migration->up();
        $this->assertTrue(Schema::hasColumns('athlete_queue_items', ['item_id', 'deleted_at']));
    }
}
