<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use App\Sync\Models\AthleteBlueprint;
use App\Sync\Models\AthletePreference;
use App\Sync\Models\AthleteQueueItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Queue pull: the athlete reads their queue back through GET /queue/changes and GET /restore
 * (issue #23, S3).
 */
class AthleteQueuePullTest extends TestCase
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

    private function getAs(string $uri, ?User $user = null): TestResponse
    {
        return $this->withHeaders($this->bearerHeadersFor($user ?? $this->athlete))->getJson($uri);
    }

    private function queueItemJson(string $id, string $updatedAt, string $title = 'Leg day'): string
    {
        return json_encode([
            'id' => $id,
            'updatedAt' => $updatedAt,
            'source' => 'generated',
            'blocks' => [['type' => 'strength', 'exercises' => [['name' => 'Back Squat', 'sets' => 5]]]],
            'meta' => ['title' => $title],
            'generatorConfig' => ['focus' => 'lower'],
        ]);
    }

    private function storeItem(string $id, string $updatedAt, ?User $user = null, bool $tombstoned = false, ?string $json = null): AthleteQueueItem
    {
        return AthleteQueueItem::factory()->create([
            'user_id' => ($user ?? $this->athlete)->id,
            'item_id' => $id,
            'item' => $json ?? $this->queueItemJson($id, $updatedAt),
            'item_updated_at' => Carbon::parse($updatedAt),
            'deleted_at' => $tombstoned ? Carbon::now() : null,
        ]);
    }

    public function test_changes_needs_a_token(): void
    {
        $this->getJson('/api/sync/queue/changes')->assertStatus(401);
    }

    public function test_changes_for_an_athlete_with_no_queue_is_empty(): void
    {
        $response = $this->getAs('/api/sync/queue/changes');

        $response->assertOk();
        $this->assertSame(['items' => [], 'deleted_ids' => []], $response->json());
        $this->assertStringContainsString('"items":[]', $response->getContent());
        $this->assertStringContainsString('"deleted_ids":[]', $response->getContent());
    }

    public function test_changes_serves_every_live_item_as_stored_and_every_tombstoned_id(): void
    {
        $this->storeItem('q-a', '2026-11-20T08:00:00.000Z');
        $this->storeItem('q-b', '2026-11-21T08:00:00.000Z');
        $this->storeItem('q-gone', '2026-11-19T08:00:00.000Z', tombstoned: true);

        $response = $this->getAs('/api/sync/queue/changes');

        $response->assertOk();
        $items = $response->json('items');
        $this->assertCount(2, $items);
        $this->assertEqualsCanonicalizing(['q-a', 'q-b'], array_column($items, 'id'));
        $this->assertSame(['q-gone'], $response->json('deleted_ids'));

        $byId = array_column($items, null, 'id');
        $this->assertSame(json_decode($this->queueItemJson('q-a', '2026-11-20T08:00:00.000Z'), true), $byId['q-a']);
        $this->assertSame(json_decode($this->queueItemJson('q-b', '2026-11-21T08:00:00.000Z'), true), $byId['q-b']);
    }

    public function test_a_tombstoned_item_is_never_served_as_live(): void
    {
        $this->storeItem('q-gone', '2026-11-19T08:00:00.000Z', tombstoned: true);

        $response = $this->getAs('/api/sync/queue/changes');

        $this->assertSame([], $response->json('items'));
        $this->assertSame(['q-gone'], $response->json('deleted_ids'));
    }

    public function test_changes_keeps_empty_objects_and_floats_as_stored(): void
    {
        $json = '{"id":"q-raw","updatedAt":"2026-11-20T08:00:00.000Z","source":"custom","meta":{},"blocks":[],"generatorConfig":{"load":1.5,"ratio":2.0}}';
        $this->storeItem('q-raw', '2026-11-20T08:00:00.000Z', json: $json);

        $content = $this->getAs('/api/sync/queue/changes')->assertOk()->getContent();

        $this->assertStringContainsString('"meta":{}', $content);
        $this->assertStringContainsString('"blocks":[]', $content);
        $this->assertStringContainsString('"ratio":2.0', $content);
        $this->assertStringContainsString('"load":1.5', $content);
    }

    public function test_changes_after_a_push_and_a_delete_reflects_them(): void
    {
        $headers = $this->bearerHeadersFor($this->athlete);
        $this->withHeaders($headers)->postJson('/api/sync/queue/sync', [
            'items' => [
                ['id' => 'q-keep', 'updatedAt' => '2026-11-20T08:00:00.000Z', 'source' => 'generated', 'meta' => ['title' => 'Keep']],
                ['id' => 'q-drop', 'updatedAt' => '2026-11-20T08:00:00.000Z', 'source' => 'custom', 'meta' => ['title' => 'Drop']],
                ['id' => 'q-coach', 'updatedAt' => '2026-11-20T08:00:00.000Z', 'source' => 'coach'],
            ],
            'deletedIds' => [],
        ])->assertOk();
        $this->withHeaders($this->bearerHeadersFor($this->athlete))->deleteJson('/api/sync/queue/q-drop')->assertOk();

        $response = $this->getAs('/api/sync/queue/changes');

        $this->assertSame(['q-keep'], array_column($response->json('items'), 'id'));
        $this->assertSame('Keep', $response->json('items.0.meta.title'));
        $this->assertSame(['q-drop'], $response->json('deleted_ids'));
    }

    public function test_changes_never_shows_another_athletes_items(): void
    {
        $other = User::factory()->create();
        $this->storeItem('q-mine', '2026-11-20T08:00:00.000Z');
        $this->storeItem('q-theirs', '2026-11-20T08:00:00.000Z', $other);
        $this->storeItem('q-theirs-gone', '2026-11-20T08:00:00.000Z', $other, tombstoned: true);

        $mine = $this->getAs('/api/sync/queue/changes');
        $this->assertSame(['q-mine'], array_column($mine->json('items'), 'id'));
        $this->assertSame([], $mine->json('deleted_ids'));

        $theirs = $this->getAs('/api/sync/queue/changes', $other);
        $this->assertSame(['q-theirs'], array_column($theirs->json('items'), 'id'));
        $this->assertSame(['q-theirs-gone'], $theirs->json('deleted_ids'));
    }

    public function test_restore_includes_an_empty_queue_when_the_athlete_has_none(): void
    {
        $response = $this->getAs('/api/sync/restore');

        $response->assertOk();
        $this->assertSame([], $response->json('queue'));
        $this->assertStringContainsString('"queue":[]', $response->getContent());
    }

    public function test_restore_includes_live_queue_items_only(): void
    {
        $this->storeItem('q-a', '2026-11-20T08:00:00.000Z');
        $this->storeItem('q-gone', '2026-11-19T08:00:00.000Z', tombstoned: true);

        $response = $this->getAs('/api/sync/restore');

        $response->assertOk();
        $queue = $response->json('queue');
        $this->assertSame(['q-a'], array_column($queue, 'id'));
        $this->assertSame(json_decode($this->queueItemJson('q-a', '2026-11-20T08:00:00.000Z'), true), $queue[0]);
    }

    public function test_restore_never_includes_another_athletes_queue_items(): void
    {
        $other = User::factory()->create();
        $this->storeItem('q-theirs', '2026-11-20T08:00:00.000Z', $other);

        $this->assertSame([], $this->getAs('/api/sync/restore')->json('queue'));
        $this->assertSame(['q-theirs'], array_column($this->getAs('/api/sync/restore', $other)->json('queue'), 'id'));
    }

    public function test_restore_adds_queue_as_the_last_key_and_leaves_every_other_key_unchanged(): void
    {
        AthleteBlueprint::create(['user_id' => $this->athlete->id, 'blueprint_data' => ['custom_routines' => ['A']]]);
        AthletePreference::create(['user_id' => $this->athlete->id, 'preferences_data' => ['dark_mode' => true]]);

        $withoutQueue = $this->getAs('/api/sync/restore')->assertOk()->getContent();

        $this->storeItem('q-a', '2026-11-20T08:00:00.000Z');
        $this->storeItem('q-b', '2026-11-21T08:00:00.000Z');
        $withQueue = $this->getAs('/api/sync/restore')->assertOk()->getContent();

        $this->assertSame(
            ['status', 'blueprint', 'preferences', 'logs', 'prHistory', 'userExercises', 'queue'],
            array_keys(json_decode($withQueue, true)),
        );
        $this->assertSame($this->withoutQueueKey($withoutQueue), $this->withoutQueueKey($withQueue));
        $this->assertSame(
            '{"status":"ok","blueprint":{"custom_routines":["A"]},"preferences":{"dark_mode":true},"logs":[],"prHistory":{},"userExercises":[]}',
            $this->withoutQueueKey($withQueue),
        );
    }

    private function withoutQueueKey(string $content): string
    {
        $position = strpos($content, ',"queue":');
        $this->assertNotFalse($position, 'The restore answer has no queue key.');

        return substr($content, 0, $position).'}';
    }
}
