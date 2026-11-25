<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use App\Sync\Models\AthleteQueueItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Queue sync: the athlete pushes their workout-queue items and deletes them explicitly (issue #23, S2).
 */
class AthleteQueueSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $athlete;

    protected function setUp(): void
    {
        parent::setUp();

        $this->athlete = User::factory()->create();
        Carbon::setTestNow('2026-11-24 12:00:00');
    }

    /**
     * @return array<string, string>
     */
    private function bearerHeadersFor(User $user): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /**
     * @param  array<int, mixed>  $items
     * @param  array<int, mixed>  $deletedIds
     */
    private function push(array $items, array $deletedIds = [], ?User $user = null): TestResponse
    {
        return $this->withHeaders($this->bearerHeadersFor($user ?? $this->athlete))
            ->postJson('/api/sync/queue/sync', ['items' => $items, 'deletedIds' => $deletedIds]);
    }

    private function pushRaw(string $body, ?User $user = null): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $token = ($user ?? $this->athlete)->createToken('test')->plainTextToken;

        return $this->call('POST', '/api/sync/queue/sync', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], $body);
    }

    private function deleteItem(string $itemId, ?User $user = null): TestResponse
    {
        return $this->withHeaders($this->bearerHeadersFor($user ?? $this->athlete))
            ->deleteJson('/api/sync/queue/'.$itemId);
    }

    /**
     * @return array<string, mixed>
     */
    private function item(string $id, string $updatedAt, string $title = 'Leg day', string $source = 'generated'): array
    {
        return [
            'id' => $id,
            'updatedAt' => $updatedAt,
            'source' => $source,
            'blocks' => [['type' => 'strength', 'exercises' => [['name' => 'Back Squat', 'sets' => 5]]]],
            'meta' => ['title' => $title],
            'generatorConfig' => ['focus' => 'lower'],
        ];
    }

    /** The sync API answers a 422 as `{status, message}`, the message naming the first bad field. */
    private function assertRefused(TestResponse $response, string $field): void
    {
        $response->assertUnprocessable()->assertJsonPath('status', 'error');
        $this->assertStringContainsString(
            'The '.$field.' ',
            (string) $response->json('message'),
        );
    }

    private function stored(string $itemId, ?User $user = null): ?AthleteQueueItem
    {
        return AthleteQueueItem::query()
            ->where('user_id', ($user ?? $this->athlete)->id)
            ->where('item_id', $itemId)
            ->first();
    }

    public function test_first_push_stores_every_item(): void
    {
        $this->push([
            $this->item('q-1', '2026-11-24T10:00:00.000Z'),
            $this->item('q-2', '2026-11-24T10:05:00.250Z', 'Push day', 'custom'),
        ])
            ->assertOk()
            ->assertExactJson(['ok' => true, 'stored' => 2, 'skipped_coach' => 0]);

        $first = $this->stored('q-1');
        $this->assertNotNull($first);
        $this->assertNull($first->deleted_at);
        $this->assertSame($this->item('q-1', '2026-11-24T10:00:00.000Z'), json_decode($first->item, true));
        $this->assertSame('2026-11-24 10:00:00.000000', $first->item_updated_at->format('Y-m-d H:i:s.u'));
        $this->assertSame('2026-11-24 10:05:00.250000', $this->stored('q-2')->item_updated_at->format('Y-m-d H:i:s.u'));
    }

    public function test_updated_at_with_an_offset_is_stored_in_utc(): void
    {
        $this->push([$this->item('q-1', '2026-11-24T12:00:00+02:00')])->assertOk();

        $this->assertSame('2026-11-24 10:00:00', $this->stored('q-1')->item_updated_at->format('Y-m-d H:i:s'));
    }

    public function test_the_item_is_kept_as_sent_without_interpreting_it(): void
    {
        $body = '{"items":[{"id":"q-1","updatedAt":"2026-11-24T10:00:00.000Z","source":"generated","blocks":[],"meta":{},"generatorConfig":{"weight":100.0,"note":"a/b é","nested":{"empty":{},"list":[1,{"y":null}]}},"future":"field"}],"deletedIds":[]}';

        $this->pushRaw($body)->assertOk()->assertJsonPath('stored', 1);

        $this->assertSame(
            '{"id":"q-1","updatedAt":"2026-11-24T10:00:00.000Z","source":"generated","blocks":[],"meta":{},"generatorConfig":{"weight":100.0,"note":"a/b é","nested":{"empty":{},"list":[1,{"y":null}]}},"future":"field"}',
            $this->stored('q-1')->item,
        );
    }

    public function test_an_older_updated_at_is_ignored(): void
    {
        $this->push([$this->item('q-1', '2026-11-24T10:00:00.000Z', 'Newer')])->assertOk();

        $this->push([$this->item('q-1', '2026-11-24T09:59:59.999Z', 'Older')])
            ->assertOk()
            ->assertExactJson(['ok' => true, 'stored' => 0, 'skipped_coach' => 0]);

        $stored = $this->stored('q-1');
        $this->assertSame('Newer', json_decode($stored->item, true)['meta']['title']);
        $this->assertSame('2026-11-24 10:00:00.000000', $stored->item_updated_at->format('Y-m-d H:i:s.u'));
    }

    public function test_an_equal_updated_at_does_not_replace(): void
    {
        $this->push([$this->item('q-1', '2026-11-24T10:00:00.000Z', 'First')])->assertOk();

        $this->push([$this->item('q-1', '2026-11-24T10:00:00.000Z', 'Second')])
            ->assertOk()
            ->assertJsonPath('stored', 0);

        $this->assertSame('First', json_decode($this->stored('q-1')->item, true)['meta']['title']);
    }

    public function test_a_newer_updated_at_replaces(): void
    {
        $this->push([$this->item('q-1', '2026-11-24T10:00:00.000Z', 'Old')])->assertOk();

        $this->push([$this->item('q-1', '2026-11-24T10:00:00.001Z', 'New')])
            ->assertOk()
            ->assertJsonPath('stored', 1);

        $stored = $this->stored('q-1');
        $this->assertSame('New', json_decode($stored->item, true)['meta']['title']);
        $this->assertSame('2026-11-24 10:00:00.001000', $stored->item_updated_at->format('Y-m-d H:i:s.u'));
        $this->assertSame(1, AthleteQueueItem::query()->count());
    }

    public function test_an_item_missing_from_a_push_is_left_alone(): void
    {
        $this->push([
            $this->item('q-1', '2026-11-24T10:00:00.000Z'),
            $this->item('q-2', '2026-11-24T10:00:00.000Z'),
        ])->assertOk();

        $this->push([$this->item('q-2', '2026-11-24T11:00:00.000Z')])->assertOk();
        $this->push([])->assertOk()->assertExactJson(['ok' => true, 'stored' => 0, 'skipped_coach' => 0]);

        $this->assertNull($this->stored('q-1')->deleted_at);
        $this->assertNull($this->stored('q-2')->deleted_at);
    }

    public function test_deleted_ids_tombstone_the_item(): void
    {
        $this->push([$this->item('q-1', '2026-11-24T10:00:00.000Z'), $this->item('q-2', '2026-11-24T10:00:00.000Z')])->assertOk();

        $this->push([], ['q-1'])->assertOk()->assertExactJson(['ok' => true, 'stored' => 0, 'skipped_coach' => 0]);

        $tombstone = $this->stored('q-1');
        $this->assertNotNull($tombstone);
        $this->assertSame('2026-11-24 12:00:00', $tombstone->deleted_at->format('Y-m-d H:i:s'));
        $this->assertNull($this->stored('q-2')->deleted_at);
    }

    public function test_delete_endpoint_tombstones_the_item(): void
    {
        $this->push([$this->item('q-1', '2026-11-24T10:00:00.000Z')])->assertOk();

        $this->deleteItem('q-1')->assertOk()->assertExactJson(['ok' => true]);

        $this->assertSame('2026-11-24 12:00:00', $this->stored('q-1')->deleted_at->format('Y-m-d H:i:s'));
    }

    public function test_deleting_is_idempotent(): void
    {
        $this->push([$this->item('q-1', '2026-11-24T10:00:00.000Z')])->assertOk();
        $this->deleteItem('q-1')->assertOk();

        Carbon::setTestNow('2026-11-24 13:00:00');
        $this->deleteItem('q-1')->assertOk()->assertExactJson(['ok' => true]);
        $this->push([], ['q-1'])->assertOk();

        $this->assertSame('2026-11-24 12:00:00', $this->stored('q-1')->deleted_at->format('Y-m-d H:i:s'));
    }

    public function test_deleting_an_unknown_id_is_fine_and_stores_nothing(): void
    {
        $this->deleteItem('q-never-seen')->assertOk()->assertExactJson(['ok' => true]);
        $this->push([], ['q-also-unknown'])->assertOk();

        $this->assertSame(0, AthleteQueueItem::query()->count());
    }

    public function test_a_push_newer_than_the_tombstone_revives_it(): void
    {
        $this->push([$this->item('q-1', '2026-11-24T10:00:00.000Z', 'Original')])->assertOk();
        $this->deleteItem('q-1')->assertOk();

        $this->push([$this->item('q-1', '2026-11-24T12:00:00.001Z', 'Regenerated')])
            ->assertOk()
            ->assertJsonPath('stored', 1);

        $revived = $this->stored('q-1');
        $this->assertNull($revived->deleted_at);
        $this->assertSame('Regenerated', json_decode($revived->item, true)['meta']['title']);
    }

    public function test_a_push_not_newer_than_the_tombstone_leaves_it_deleted(): void
    {
        $this->push([$this->item('q-1', '2026-11-24T10:00:00.000Z', 'Original')])->assertOk();
        $this->deleteItem('q-1')->assertOk();

        $this->push([$this->item('q-1', '2026-11-24T11:00:00.000Z', 'Stale device')])->assertOk()->assertJsonPath('stored', 0);
        $this->push([$this->item('q-1', '2026-11-24T12:00:00.000Z', 'Same instant')])->assertOk()->assertJsonPath('stored', 0);

        $tombstone = $this->stored('q-1');
        $this->assertNotNull($tombstone->deleted_at);
        $this->assertSame('Original', json_decode($tombstone->item, true)['meta']['title']);
    }

    public function test_a_tombstone_is_not_revived_by_the_same_item_pushed_again(): void
    {
        $this->push([$this->item('q-1', '2026-11-24T14:00:00.000Z', 'Clock ahead')])->assertOk();
        $this->deleteItem('q-1')->assertOk();

        $this->push([$this->item('q-1', '2026-11-24T14:00:00.000Z', 'Clock ahead')])->assertOk()->assertJsonPath('stored', 0);

        $this->assertNotNull($this->stored('q-1')->deleted_at);
    }

    public function test_an_item_pushed_and_deleted_in_one_request_ends_deleted(): void
    {
        $this->push([$this->item('q-1', '2026-11-24T10:00:00.000Z')])->assertOk();

        $this->push([$this->item('q-1', '2026-11-24T13:00:00.000Z', 'Edited')], ['q-1'])->assertOk();
        $this->push([$this->item('q-new', '2026-11-24T13:00:00.000Z')], ['q-new'])->assertOk();

        $this->assertNotNull($this->stored('q-1')->deleted_at);
        $this->assertNotNull($this->stored('q-new')->deleted_at);
    }

    public function test_a_coach_item_is_skipped_and_counted(): void
    {
        $this->push([
            $this->item('q-1', '2026-11-24T10:00:00.000Z'),
            $this->item('coach-1', '2026-11-24T10:00:00.000Z', 'From coach', 'coach'),
            ['id' => 'coach-2', 'source' => 'coach'],
            ['source' => 'coach', 'updatedAt' => 'not a date'],
        ])
            ->assertOk()
            ->assertExactJson(['ok' => true, 'stored' => 1, 'skipped_coach' => 3]);

        $this->assertSame(['q-1'], AthleteQueueItem::query()->pluck('item_id')->all());
    }

    public function test_a_coach_item_does_not_replace_a_stored_item_with_its_id(): void
    {
        $this->push([$this->item('q-1', '2026-11-24T10:00:00.000Z', 'Mine')])->assertOk();

        $this->push([$this->item('q-1', '2026-11-24T11:00:00.000Z', 'Coach', 'coach')])
            ->assertOk()
            ->assertExactJson(['ok' => true, 'stored' => 0, 'skipped_coach' => 1]);

        $this->assertSame('Mine', json_decode($this->stored('q-1')->item, true)['meta']['title']);
    }

    public function test_two_athletes_are_isolated(): void
    {
        $other = User::factory()->create();
        $this->push([$this->item('q-shared', '2026-11-24T10:00:00.000Z', 'Mine')])->assertOk();

        $this->push([$this->item('q-shared', '2026-11-24T11:00:00.000Z', 'Theirs')], [], $other)
            ->assertOk()
            ->assertJsonPath('stored', 1);
        $this->push([], ['q-shared'], $other)->assertOk();
        $this->deleteItem('q-shared', $other)->assertOk();

        $mine = $this->stored('q-shared');
        $this->assertNull($mine->deleted_at);
        $this->assertSame('Mine', json_decode($mine->item, true)['meta']['title']);
        $this->assertSame('2026-11-24 10:00:00', $mine->item_updated_at->format('Y-m-d H:i:s'));
        $this->assertNotNull($this->stored('q-shared', $other)->deleted_at);
    }

    public function test_a_push_may_hold_200_items(): void
    {
        $items = array_map(fn (int $n): array => $this->item("q-{$n}", '2026-11-24T10:00:00.000Z'), range(1, 200));

        $this->push($items)->assertOk()->assertJsonPath('stored', 200);
    }

    public function test_a_push_of_201_items_is_refused(): void
    {
        $items = array_map(fn (int $n): array => $this->item("q-{$n}", '2026-11-24T10:00:00.000Z'), range(1, 201));

        $this->assertRefused($this->push($items), 'items');
        $this->assertSame(0, AthleteQueueItem::query()->count());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function malformedBodies(): array
    {
        $valid = ['id' => 'q-1', 'updatedAt' => '2026-11-24T10:00:00.000Z', 'source' => 'generated'];

        return [
            'items not a list' => [['items' => 'nope', 'deletedIds' => []], 'items'],
            'item not an object' => [['items' => ['q-1'], 'deletedIds' => []], 'items.0'],
            'missing id' => [['items' => [array_diff_key($valid, ['id' => true])], 'deletedIds' => []], 'items.0.id'],
            'id not a string' => [['items' => [[...$valid, 'id' => 42]], 'deletedIds' => []], 'items.0.id'],
            'id too long' => [['items' => [[...$valid, 'id' => str_repeat('a', 192)]], 'deletedIds' => []], 'items.0.id'],
            'duplicate id' => [['items' => [$valid, $valid], 'deletedIds' => []], 'items.0.id'],
            'missing updatedAt' => [['items' => [array_diff_key($valid, ['updatedAt' => true])], 'deletedIds' => []], 'items.0.updatedAt'],
            'updatedAt not ISO-8601' => [['items' => [[...$valid, 'updatedAt' => '24/11/2026 10:00']], 'deletedIds' => []], 'items.0.updatedAt'],
            'updatedAt not a date' => [['items' => [[...$valid, 'updatedAt' => '2026-13-45T10:00:00Z']], 'deletedIds' => []], 'items.0.updatedAt'],
            'deletedIds not a list' => [['items' => [], 'deletedIds' => 'q-1'], 'deleted ids'],
            'deleted id not a string' => [['items' => [], 'deletedIds' => [7]], 'deletedIds.0'],
            'deleted id too long' => [['items' => [], 'deletedIds' => [str_repeat('a', 192)]], 'deletedIds.0'],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('malformedBodies')]
    public function test_a_malformed_body_is_refused(array $body, string $field): void
    {
        $this->assertRefused(
            $this->withHeaders($this->bearerHeadersFor($this->athlete))->postJson('/api/sync/queue/sync', $body),
            $field,
        );

        $this->assertSame(0, AthleteQueueItem::query()->count());
    }

    public function test_a_malformed_body_writes_nothing_even_with_valid_items(): void
    {
        $this->push([
            $this->item('q-1', '2026-11-24T10:00:00.000Z'),
            ['id' => 'q-2', 'updatedAt' => 'yesterday'],
        ])->assertUnprocessable();

        $this->assertSame(0, AthleteQueueItem::query()->count());
    }

    public function test_missing_keys_count_as_empty_lists(): void
    {
        $this->withHeaders($this->bearerHeadersFor($this->athlete))
            ->postJson('/api/sync/queue/sync', ['items' => [$this->item('q-1', '2026-11-24T10:00:00.000Z')]])
            ->assertOk()
            ->assertJsonPath('stored', 1);

        $this->withHeaders($this->bearerHeadersFor($this->athlete))
            ->postJson('/api/sync/queue/sync', ['deletedIds' => ['q-1']])
            ->assertOk()
            ->assertJsonPath('stored', 0);

        $this->assertNotNull($this->stored('q-1')->deleted_at);
    }

    public function test_requests_without_a_token_are_rejected(): void
    {
        $this->postJson('/api/sync/queue/sync', ['items' => [], 'deletedIds' => []])->assertUnauthorized();
        $this->deleteJson('/api/sync/queue/q-1')->assertUnauthorized();
    }
}
