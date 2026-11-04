<?php

namespace Tests\Feature\Sync\Prism;

use App\Models\User;
use App\Sync\Models\Gym;
use App\Sync\Models\PrismProgramCell;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrismCellsStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Gym::create(['id' => 'the-cove', 'name' => 'The Cove']);
    }

    public function test_posting_cells_creates_rows(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $payload = [
            'cells' => [
                ['cell_id' => 'w1d1', 'text' => 'Back Squat 3x5'],
                ['cell_id' => 'w1d2', 'text' => 'Bench Press 3x5'],
            ],
        ];

        $response = $this->withHeaders(['X-Device-Id' => 'dev-uuid-1'])
            ->postJson('/api/sync/prism/the-cove/programs/prog-123/cells', $payload);

        $response->assertStatus(200)
            ->assertJson(['status' => 'ok'])
            ->assertJsonStructure(['status', 'updated_at']);

        $this->assertDatabaseCount('prism_program_cells', 2);

        $cell1 = PrismProgramCell::where('cell_id', 'w1d1')->first();
        $this->assertEquals('the-cove', $cell1->gym_id);
        $this->assertEquals('prog-123', $cell1->program_uuid);
        $this->assertEquals('Back Squat 3x5', $cell1->text);
        $this->assertEquals('dev-uuid-1', $cell1->device_id);

        $cell2 = PrismProgramCell::where('cell_id', 'w1d2')->first();
        $this->assertEquals('Bench Press 3x5', $cell2->text);
    }

    public function test_reposting_one_cell_with_new_text_updates_in_place(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->withHeaders(['X-Device-Id' => 'dev-uuid-1'])
            ->postJson('/api/sync/prism/the-cove/programs/prog-123/cells', [
                'cells' => [
                    ['cell_id' => 'w1d1', 'text' => 'Back Squat 3x5'],
                    ['cell_id' => 'w1d2', 'text' => 'Bench Press 3x5'],
                ],
            ])->assertStatus(200);

        $this->assertDatabaseCount('prism_program_cells', 2);

        $response = $this->withHeaders(['X-Device-Id' => 'dev-uuid-2'])
            ->postJson('/api/sync/prism/the-cove/programs/prog-123/cells', [
                'cells' => [
                    ['cell_id' => 'w1d1', 'text' => 'Back Squat 5x5 @ 225'],
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'ok']);

        $this->assertDatabaseCount('prism_program_cells', 2);

        $cell1 = PrismProgramCell::where('cell_id', 'w1d1')->first();
        $this->assertEquals('Back Squat 5x5 @ 225', $cell1->text);
        $this->assertEquals('dev-uuid-2', $cell1->device_id);
    }

    public function test_empty_string_text_is_accepted_as_emptied_cell(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->withHeaders(['X-Device-Id' => 'dev-uuid-1'])
            ->postJson('/api/sync/prism/the-cove/programs/prog-123/cells', [
                'cells' => [
                    ['cell_id' => 'w1d1', 'text' => ''],
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'ok']);

        $cell = PrismProgramCell::first();
        $this->assertEquals('', $cell->text);
    }

    public function test_unknown_gym_returns_404(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->withHeaders(['X-Device-Id' => 'dev-uuid-1'])
            ->postJson('/api/sync/prism/nonexistent-gym/programs/prog-123/cells', [
                'cells' => [
                    ['cell_id' => 'w1d1', 'text' => 'Squat'],
                ],
            ]);

        $response->assertStatus(404)
            ->assertJson([
                'status' => 'error',
                'message' => 'Gym not found.',
            ]);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->withHeaders(['X-Device-Id' => 'dev-uuid-1'])
            ->postJson('/api/sync/prism/the-cove/programs/prog-123/cells', [
                'cells' => [
                    ['cell_id' => 'w1d1', 'text' => 'Squat'],
                ],
            ]);

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_missing_cells_and_removals_returns_422(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->withHeaders(['X-Device-Id' => 'dev-uuid-1'])
            ->postJson('/api/sync/prism/the-cove/programs/prog-123/cells', []);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
            ]);
    }

    public function test_removals_only_payload_is_accepted(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->withHeaders(['X-Device-Id' => 'dev-uuid-1'])
            ->postJson('/api/sync/prism/the-cove/programs/prog-123/cells', [
                'cells' => [],
                'removals' => [
                    ['cell_id' => 'w1d1'],
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'ok'])
            ->assertJsonStructure(['status', 'updated_at']);
    }

    public function test_removal_writes_tombstone(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // Pre-create a cell
        PrismProgramCell::create([
            'gym_id' => 'the-cove',
            'program_uuid' => 'prog-123',
            'cell_id' => 'w1d1',
            'text' => 'Back Squat 3x5',
            'device_id' => 'dev-uuid-old',
            'deleted_at' => null,
        ]);

        $response = $this->withHeaders(['X-Device-Id' => 'dev-uuid-2'])
            ->postJson('/api/sync/prism/the-cove/programs/prog-123/cells', [
                'cells' => [],
                'removals' => [
                    ['cell_id' => 'w1d1'],
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'ok']);

        // The row is kept (tombstone), not physically removed.
        $this->assertDatabaseCount('prism_program_cells', 1);

        $cell = PrismProgramCell::first();
        $this->assertNotNull($cell->deleted_at);
        $this->assertEquals('', $cell->text);
        $this->assertEquals('dev-uuid-2', $cell->device_id);
    }

    public function test_removing_a_nonexistent_cell_inserts_a_tombstone(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->withHeaders(['X-Device-Id' => 'dev-uuid-1'])
            ->postJson('/api/sync/prism/the-cove/programs/prog-123/cells', [
                'cells' => [],
                'removals' => [
                    ['cell_id' => 'w9d9'],
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'ok']);

        $this->assertDatabaseCount('prism_program_cells', 1);

        $cell = PrismProgramCell::first();
        $this->assertEquals('w9d9', $cell->cell_id);
        $this->assertNotNull($cell->deleted_at);
    }

    public function test_re_upserting_tombstoned_cell_clears_deleted_at(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // First remove (tombstone)
        $this->withHeaders(['X-Device-Id' => 'dev-uuid-1'])
            ->postJson('/api/sync/prism/the-cove/programs/prog-123/cells', [
                'cells' => [],
                'removals' => [
                    ['cell_id' => 'w1d1'],
                ],
            ])->assertStatus(200);

        $cell = PrismProgramCell::first();
        $this->assertNotNull($cell->deleted_at);

        // Re-create with the same cell_id
        $response = $this->withHeaders(['X-Device-Id' => 'dev-uuid-2'])
            ->postJson('/api/sync/prism/the-cove/programs/prog-123/cells', [
                'cells' => [
                    ['cell_id' => 'w1d1', 'text' => 'Back Squat 5x5'],
                ],
                'removals' => [],
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseCount('prism_program_cells', 1);

        $cell = PrismProgramCell::first();
        $this->assertNull($cell->deleted_at);
        $this->assertEquals('Back Squat 5x5', $cell->text);
        $this->assertEquals('dev-uuid-2', $cell->device_id);
    }

    public function test_unknown_gym_with_removal_returns_404(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->withHeaders(['X-Device-Id' => 'dev-uuid-1'])
            ->postJson('/api/sync/prism/nonexistent-gym/programs/prog-123/cells', [
                'cells' => [],
                'removals' => [
                    ['cell_id' => 'w1d1'],
                ],
            ]);

        $response->assertStatus(404)
            ->assertJson([
                'status' => 'error',
                'message' => 'Gym not found.',
            ]);
    }

    private function postCells(array $payload, string $device = 'dev-uuid-1')
    {
        return $this->withHeaders(['X-Device-Id' => $device])
            ->postJson('/api/sync/prism/the-cove/programs/prog-123/cells', $payload);
    }

    public function test_a_cell_with_a_workout_uuid_stores_it(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postCells(['cells' => [
            ['cell_id' => 'w1d1', 'text' => 'Back Squat 3x5', 'workout_uuid' => '7d9f2c1e-8a4b-4c3d-9e2f-1a2b3c4d5e6f'],
        ]])->assertStatus(200);

        $this->assertEquals('7d9f2c1e-8a4b-4c3d-9e2f-1a2b3c4d5e6f', PrismProgramCell::first()->workout_uuid);
    }

    public function test_a_cell_without_a_workout_uuid_keeps_the_stored_one(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postCells(['cells' => [
            ['cell_id' => 'w1d1', 'text' => 'Back Squat 3x5', 'workout_uuid' => '7d9f2c1e-8a4b-4c3d-9e2f-1a2b3c4d5e6f'],
        ]])->assertStatus(200);
        $this->postCells(['cells' => [
            ['cell_id' => 'w1d1', 'text' => 'Back Squat 5x5'],
        ]], 'old-client')->assertStatus(200);

        $cell = PrismProgramCell::first();
        $this->assertEquals('Back Squat 5x5', $cell->text);
        $this->assertEquals('7d9f2c1e-8a4b-4c3d-9e2f-1a2b3c4d5e6f', $cell->workout_uuid);
    }

    public function test_a_new_workout_uuid_replaces_the_stored_one(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postCells(['cells' => [
            ['cell_id' => 'w1d1', 'text' => 'Back Squat 3x5', 'workout_uuid' => '7d9f2c1e-8a4b-4c3d-9e2f-1a2b3c4d5e6f'],
        ]])->assertStatus(200);
        $this->postCells(['cells' => [
            ['cell_id' => 'w1d1', 'text' => 'Bench Press 3x5', 'workout_uuid' => '0b1c2d3e-4f5a-4b6c-8d7e-9f0a1b2c3d4e'],
        ]])->assertStatus(200);

        $this->assertEquals('0b1c2d3e-4f5a-4b6c-8d7e-9f0a1b2c3d4e', PrismProgramCell::first()->workout_uuid);
    }

    public function test_a_removal_clears_the_workout_uuid(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postCells(['cells' => [
            ['cell_id' => 'w1d1', 'text' => 'Back Squat 3x5', 'workout_uuid' => '7d9f2c1e-8a4b-4c3d-9e2f-1a2b3c4d5e6f'],
        ]])->assertStatus(200);
        $this->postCells(['cells' => [], 'removals' => [['cell_id' => 'w1d1']]])->assertStatus(200);

        $cell = PrismProgramCell::first();
        $this->assertNotNull($cell->deleted_at);
        $this->assertNull($cell->workout_uuid);
    }

    public function test_a_malformed_workout_uuid_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postCells(['cells' => [
            ['cell_id' => 'w1d1', 'text' => 'Back Squat 3x5', 'workout_uuid' => 'not-a-uuid'],
        ]])->assertStatus(422);

        $this->assertDatabaseCount('prism_program_cells', 0);
    }

    public function test_a_batch_mixing_cells_with_and_without_a_uuid_stores_both(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postCells(['cells' => [
            ['cell_id' => 'w1d1', 'text' => 'Back Squat 3x5', 'workout_uuid' => '7d9f2c1e-8a4b-4c3d-9e2f-1a2b3c4d5e6f'],
            ['cell_id' => 'w1d2', 'text' => 'Bench Press 3x5'],
        ]])->assertStatus(200);

        $this->assertEquals('7d9f2c1e-8a4b-4c3d-9e2f-1a2b3c4d5e6f', PrismProgramCell::where('cell_id', 'w1d1')->first()->workout_uuid);
        $this->assertNull(PrismProgramCell::where('cell_id', 'w1d2')->first()->workout_uuid);
    }
}
