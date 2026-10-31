<?php

namespace Tests\Feature\Sync\Prism;

use App\Models\User;
use App\Sync\Models\Gym;
use App\Sync\Models\PrismProgram;
use App\Sync\Models\PrismProgramDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Program publish destinations — docs/plans/prism-program-partial-updates.md §4.
 */
class PrismProgramDestinationsStoreTest extends TestCase
{
    use RefreshDatabase;

    private User $coach;

    protected function setUp(): void
    {
        parent::setUp();
        Gym::create(['id' => 'the-cove', 'name' => 'The Cove']);
        $this->coach = User::factory()->create(['name' => 'Coach A']);
        Sanctum::actingAs($this->coach);
    }

    private function pushDestinations(array $upserts, array $removals = [], string $programUuid = 'prog-1', string $gym = 'the-cove'): TestResponse
    {
        return $this->withHeaders(['X-Device-Id' => 'dev-a'])
            ->postJson("/api/sync/prism/{$gym}/programs/{$programUuid}/destinations", [
                'upserts' => $upserts,
                'removals' => $removals,
            ]);
    }

    private function sheet(array $overrides = []): array
    {
        return array_merge([
            'type' => 'google_sheets',
            'spreadsheet_id' => '1AbC',
            'sheet_id' => 0,
            'title' => 'Spring Block 2026',
        ], $overrides);
    }

    public function test_link_creates_row_stamped_with_the_authenticated_user(): void
    {
        $response = $this->pushDestinations([$this->sheet()]);

        $response->assertStatus(200)->assertJson(['status' => 'ok'])->assertJsonStructure(['status', 'updated_at']);

        $destination = PrismProgramDestination::firstOrFail();
        $this->assertSame('the-cove', $destination->gym_id);
        $this->assertSame('prog-1', $destination->program_uuid);
        $this->assertSame('google_sheets', $destination->type);
        $this->assertSame('1AbC', $destination->spreadsheet_id);
        $this->assertSame(0, $destination->sheet_id);
        $this->assertSame('Spring Block 2026', $destination->title);
        $this->assertSame($this->coach->id, $destination->linked_by_user_id);
        $this->assertNotNull($destination->linked_at);
        $this->assertSame('dev-a', $destination->device_id);
        $this->assertNull($destination->deleted_at);
        $this->assertSame($destination->updated_at->toIso8601String(), $response->json('updated_at'));
    }

    public function test_relink_replaces_link_and_clears_tombstone(): void
    {
        $this->pushDestinations([$this->sheet()])->assertStatus(200);
        $this->pushDestinations([], [['type' => 'google_sheets']])->assertStatus(200);
        $this->assertNotNull(PrismProgramDestination::firstOrFail()->deleted_at);

        $coachB = User::factory()->create(['name' => 'Coach B']);
        Sanctum::actingAs($coachB);
        $this->pushDestinations([$this->sheet(['spreadsheet_id' => '2XyZ', 'sheet_id' => 7, 'title' => null])])
            ->assertStatus(200);

        $this->assertDatabaseCount('prism_program_destinations', 1);
        $destination = PrismProgramDestination::firstOrFail();
        $this->assertSame('2XyZ', $destination->spreadsheet_id);
        $this->assertSame(7, $destination->sheet_id);
        $this->assertNull($destination->title);
        $this->assertSame($coachB->id, $destination->linked_by_user_id);
        $this->assertNull($destination->deleted_at);
    }

    public function test_resending_the_same_link_keeps_original_attribution(): void
    {
        $this->pushDestinations([$this->sheet()])->assertStatus(200);
        $original = PrismProgramDestination::firstOrFail();

        $coachB = User::factory()->create(['name' => 'Coach B']);
        Sanctum::actingAs($coachB);
        Carbon::setTestNow(now()->addMinute());
        try {
            // Same spreadsheet + tab; only the display title changed.
            $this->pushDestinations([$this->sheet(['title' => 'Renamed tab'])])->assertStatus(200);
        } finally {
            Carbon::setTestNow();
        }

        $destination = PrismProgramDestination::firstOrFail();
        $this->assertSame($this->coach->id, $destination->linked_by_user_id);
        $this->assertTrue($original->linked_at->eq($destination->linked_at));
        $this->assertSame('Renamed tab', $destination->title);
    }

    public function test_linking_a_different_sheet_restamps_attribution(): void
    {
        $this->pushDestinations([$this->sheet()])->assertStatus(200);

        $coachB = User::factory()->create(['name' => 'Coach B']);
        Sanctum::actingAs($coachB);
        $this->pushDestinations([$this->sheet(['sheet_id' => 9])])->assertStatus(200);

        $this->assertSame($coachB->id, PrismProgramDestination::firstOrFail()->linked_by_user_id);
    }

    public function test_relinking_the_same_sheet_after_unlink_restamps_attribution(): void
    {
        $this->pushDestinations([$this->sheet()])->assertStatus(200);
        $this->pushDestinations([], [['type' => 'google_sheets']])->assertStatus(200);

        $coachB = User::factory()->create(['name' => 'Coach B']);
        Sanctum::actingAs($coachB);
        $this->pushDestinations([$this->sheet()])->assertStatus(200);

        $this->assertSame($coachB->id, PrismProgramDestination::firstOrFail()->linked_by_user_id);
    }

    public function test_unlink_writes_tombstone(): void
    {
        $this->pushDestinations([$this->sheet()])->assertStatus(200);

        $this->pushDestinations([], [['type' => 'google_sheets']])->assertStatus(200);

        $destination = PrismProgramDestination::firstOrFail();
        $this->assertNotNull($destination->deleted_at);
        $this->assertSame('1AbC', $destination->spreadsheet_id, 'tombstone keeps the row for other devices');
    }

    public function test_unlink_when_nothing_linked_is_a_noop(): void
    {
        $this->pushDestinations([], [['type' => 'google_sheets']])->assertStatus(200);

        $this->assertDatabaseCount('prism_program_destinations', 0);
    }

    public function test_unlink_already_unlinked_does_not_move_the_tombstone(): void
    {
        $this->pushDestinations([$this->sheet()])->assertStatus(200);
        $this->pushDestinations([], [['type' => 'google_sheets']])->assertStatus(200);
        $firstDeletedAt = PrismProgramDestination::firstOrFail()->deleted_at;

        Carbon::setTestNow(now()->addMinute());
        try {
            $this->pushDestinations([], [['type' => 'google_sheets']])->assertStatus(200);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertTrue($firstDeletedAt->eq(PrismProgramDestination::firstOrFail()->deleted_at));
    }

    public function test_unknown_type_returns_422(): void
    {
        $this->pushDestinations([$this->sheet(['type' => 'dropbox'])])->assertStatus(422)->assertJson(['status' => 'error']);
        $this->assertDatabaseCount('prism_program_destinations', 0);
    }

    public function test_google_sheets_requires_spreadsheet_and_sheet_ids(): void
    {
        $this->pushDestinations([['type' => 'google_sheets', 'sheet_id' => 0]])->assertStatus(422);
        $this->pushDestinations([['type' => 'google_sheets', 'spreadsheet_id' => '1AbC']])->assertStatus(422);
        $this->pushDestinations([$this->sheet(['sheet_id' => -1])])->assertStatus(422);

        $this->assertDatabaseCount('prism_program_destinations', 0);
    }

    public function test_upserts_and_removals_keys_are_required(): void
    {
        $this->withHeaders(['X-Device-Id' => 'dev-a'])
            ->postJson('/api/sync/prism/the-cove/programs/prog-1/destinations', ['upserts' => []])
            ->assertStatus(422);
    }

    public function test_destination_and_program_writes_do_not_touch_each_other(): void
    {
        // F3: linking a sheet and editing the program are independent rows.
        $this->withHeaders(['X-Device-Id' => 'dev-a'])
            ->postJson('/api/sync/prism/the-cove/programs/prog-1', ['name' => 'Spring Block', 'week_count' => 6])
            ->assertStatus(200);
        $programUpdatedAt = PrismProgram::firstOrFail()->updated_at;

        Carbon::setTestNow(now()->addMinute());
        try {
            $this->pushDestinations([$this->sheet()])->assertStatus(200);
            $this->assertTrue(PrismProgram::firstOrFail()->updated_at->eq($programUpdatedAt));

            $this->withHeaders(['X-Device-Id' => 'dev-b'])
                ->postJson('/api/sync/prism/the-cove/programs/prog-1', ['name' => 'Renamed'])
                ->assertStatus(200);
        } finally {
            Carbon::setTestNow();
        }

        $destination = PrismProgramDestination::firstOrFail();
        $this->assertSame('1AbC', $destination->spreadsheet_id);
        $this->assertNull($destination->deleted_at);
    }

    public function test_two_programs_can_share_one_spreadsheet(): void
    {
        $this->pushDestinations([$this->sheet(['sheet_id' => 0])], [], 'prog-1')->assertStatus(200);
        $this->pushDestinations([$this->sheet(['sheet_id' => 123])], [], 'prog-2')->assertStatus(200);

        $this->assertDatabaseCount('prism_program_destinations', 2);
        $this->assertSame(
            [0, 123],
            PrismProgramDestination::orderBy('program_uuid')->pluck('sheet_id')->all()
        );
    }

    public function test_unknown_gym_returns_404(): void
    {
        $this->pushDestinations([$this->sheet()], [], 'prog-1', 'nonexistent-gym')
            ->assertStatus(404)
            ->assertJson(['status' => 'error', 'message' => 'Gym not found.']);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->app['auth']->forgetGuards();

        $this->withHeaders(['X-Device-Id' => 'dev-a'])
            ->postJson('/api/sync/prism/the-cove/programs/prog-1/destinations', ['upserts' => [], 'removals' => []])
            ->assertStatus(401);
    }
}
