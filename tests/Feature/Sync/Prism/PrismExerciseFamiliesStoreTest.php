<?php

namespace Tests\Feature\Sync\Prism;

use App\Models\User;
use App\Sync\Models\Gym;
use App\Sync\Models\PrismExerciseFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrismExerciseFamiliesStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Gym::create(['id' => 'the-cove', 'name' => 'The Cove']);
    }

    public function test_upsert_stores_family_and_keeps_unknown_record_fields(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $record = [
            'id' => 'burpees',
            'name' => 'Burpees',
            'frequencyCap' => ['count' => 2, 'period' => 'week'],
            'futureField' => ['nested' => true],
        ];

        $response = $this->withHeaders(['X-Device-Id' => 'dev-uuid-1'])
            ->postJson('/api/sync/prism/the-cove/exercise-families', [
                'upserts' => [['family_id' => 'burpees', 'record' => $record]],
                'removals' => [],
            ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'ok'])
            ->assertJsonStructure(['status', 'updated_at']);

        $this->assertDatabaseCount('prism_exercise_families', 1);

        $row = PrismExerciseFamily::first();
        $this->assertEquals('the-cove', $row->gym_id);
        $this->assertEquals('burpees', $row->family_id);
        $this->assertEquals($record, $row->record);
        $this->assertEquals('dev-uuid-1', $row->device_id);
        $this->assertNull($row->deleted_at);
    }

    public function test_removal_writes_tombstone(): void
    {
        Sanctum::actingAs(User::factory()->create());

        PrismExerciseFamily::create([
            'gym_id' => 'the-cove',
            'family_id' => 'burpees',
            'record' => ['id' => 'burpees'],
            'device_id' => 'dev-uuid-old',
            'deleted_at' => null,
        ]);

        $response = $this->withHeaders(['X-Device-Id' => 'dev-uuid-2'])
            ->postJson('/api/sync/prism/the-cove/exercise-families', [
                'upserts' => [],
                'removals' => [['family_id' => 'burpees']],
            ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'ok']);

        $this->assertDatabaseCount('prism_exercise_families', 1);

        $row = PrismExerciseFamily::first();
        $this->assertNull($row->record);
        $this->assertNotNull($row->deleted_at);
        $this->assertEquals('dev-uuid-2', $row->device_id);
    }

    public function test_re_upsert_clears_tombstone(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->withHeaders(['X-Device-Id' => 'dev-uuid-2'])
            ->postJson('/api/sync/prism/the-cove/exercise-families', [
                'upserts' => [],
                'removals' => [['family_id' => 'burpees']],
            ])->assertStatus(200);

        $this->withHeaders(['X-Device-Id' => 'dev-uuid-2'])
            ->postJson('/api/sync/prism/the-cove/exercise-families', [
                'upserts' => [['family_id' => 'burpees', 'record' => ['restored' => true]]],
                'removals' => [],
            ])->assertStatus(200);

        $this->assertDatabaseCount('prism_exercise_families', 1);

        $row = PrismExerciseFamily::first();
        $this->assertNull($row->deleted_at);
        $this->assertEquals(['restored' => true], $row->record);
        $this->assertEquals('dev-uuid-2', $row->device_id);
    }

    public function test_upserts_and_removals_apply_in_one_request(): void
    {
        Sanctum::actingAs(User::factory()->create());

        PrismExerciseFamily::create([
            'gym_id' => 'the-cove',
            'family_id' => 'old-family',
            'record' => ['id' => 'old-family'],
            'device_id' => 'dev-uuid-old',
            'deleted_at' => null,
        ]);

        $response = $this->withHeaders(['X-Device-Id' => 'dev-uuid-1'])
            ->postJson('/api/sync/prism/the-cove/exercise-families', [
                'upserts' => [['family_id' => 'burpees', 'record' => ['id' => 'burpees']]],
                'removals' => [['family_id' => 'old-family']],
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseCount('prism_exercise_families', 2);

        $burpees = PrismExerciseFamily::where('family_id', 'burpees')->firstOrFail();
        $this->assertNull($burpees->deleted_at);
        $this->assertEquals(['id' => 'burpees'], $burpees->record);

        $oldFamily = PrismExerciseFamily::where('family_id', 'old-family')->firstOrFail();
        $this->assertNull($oldFamily->record);
        $this->assertNotNull($oldFamily->deleted_at);
    }

    public function test_family_in_both_lists_ends_tombstoned(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->withHeaders(['X-Device-Id' => 'dev-uuid-1'])
            ->postJson('/api/sync/prism/the-cove/exercise-families', [
                'upserts' => [['family_id' => 'burpees', 'record' => ['id' => 'burpees']]],
                'removals' => [['family_id' => 'burpees']],
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseCount('prism_exercise_families', 1);

        $row = PrismExerciseFamily::first();
        $this->assertNull($row->record);
        $this->assertNotNull($row->deleted_at);
    }

    public function test_empty_lists_return_ok_and_write_nothing(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/sync/prism/the-cove/exercise-families', [
            'upserts' => [],
            'removals' => [],
        ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'ok'])
            ->assertJsonStructure(['status', 'updated_at']);

        $this->assertDatabaseCount('prism_exercise_families', 0);
    }

    public function test_missing_device_header_stores_null_device_id(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/sync/prism/the-cove/exercise-families', [
            'upserts' => [['family_id' => 'burpees', 'record' => ['id' => 'burpees']]],
            'removals' => [],
        ]);

        $response->assertStatus(200);

        $row = PrismExerciseFamily::first();
        $this->assertNull($row->device_id);
    }

    public function test_same_family_id_is_separate_per_gym(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Gym::create(['id' => 'flagship', 'name' => 'Flagship']);

        $this->withHeaders(['X-Device-Id' => 'dev-uuid-1'])
            ->postJson('/api/sync/prism/the-cove/exercise-families', [
                'upserts' => [['family_id' => 'burpees', 'record' => ['gym' => 'cove']]],
                'removals' => [],
            ])->assertStatus(200);

        $this->withHeaders(['X-Device-Id' => 'dev-uuid-1'])
            ->postJson('/api/sync/prism/flagship/exercise-families', [
                'upserts' => [['family_id' => 'burpees', 'record' => ['gym' => 'flagship']]],
                'removals' => [],
            ])->assertStatus(200);

        $this->assertDatabaseCount('prism_exercise_families', 2);

        $this->assertEquals(
            ['gym' => 'cove'],
            PrismExerciseFamily::where('gym_id', 'the-cove')->firstOrFail()->record,
        );
        $this->assertEquals(
            ['gym' => 'flagship'],
            PrismExerciseFamily::where('gym_id', 'flagship')->firstOrFail()->record,
        );
    }

    public function test_unknown_gym_returns_404(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/sync/prism/nonexistent-gym/exercise-families', [
            'upserts' => [],
            'removals' => [],
        ]);

        $response->assertStatus(404)
            ->assertJson([
                'status' => 'error',
                'message' => 'Gym not found.',
            ]);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->postJson('/api/sync/prism/the-cove/exercise-families', [
            'upserts' => [],
            'removals' => [],
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_missing_upserts_returns_422(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/sync/prism/the-cove/exercise-families', [
            'removals' => [],
        ]);

        $response->assertStatus(422)
            ->assertJson(['status' => 'error']);
    }

    public function test_missing_removals_returns_422(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/sync/prism/the-cove/exercise-families', [
            'upserts' => [],
        ]);

        $response->assertStatus(422)
            ->assertJson(['status' => 'error']);
    }

    public function test_non_array_record_returns_422(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/sync/prism/the-cove/exercise-families', [
            'upserts' => [['family_id' => 'burpees', 'record' => 'not-an-array']],
            'removals' => [],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('prism_exercise_families', 0);
    }

    public function test_missing_family_id_returns_422(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/sync/prism/the-cove/exercise-families', [
            'upserts' => [['record' => ['id' => 'burpees']]],
            'removals' => [],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('prism_exercise_families', 0);
    }

    public function test_family_id_over_255_characters_returns_422(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/sync/prism/the-cove/exercise-families', [
            'upserts' => [['family_id' => str_repeat('a', 256), 'record' => ['id' => 'x']]],
            'removals' => [],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('prism_exercise_families', 0);
    }
}
