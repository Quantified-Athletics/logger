<?php

namespace Tests\Feature\Sync\Prism;

use App\Models\User;
use App\Sync\Models\Gym;
use App\Sync\Models\PrismProgram;
use App\Sync\Models\PrismProgramDestination;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
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

    private function momence(array $overrides = []): array
    {
        return array_merge([
            'type' => 'momence',
            'momence_track' => 'peak',
            'momence_cycle_prefix' => 'Peak Cycle',
            'momence_bridge_prefix' => 'Peak Bridge',
            'momence_bridge_weeks' => 2,
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

    public function test_valid_momence_upsert_is_stored_beside_the_sheets_row(): void
    {
        $this->pushDestinations([$this->sheet(), $this->momence()])->assertStatus(200);

        $this->assertDatabaseCount('prism_program_destinations', 2);
        $momence = PrismProgramDestination::where('type', 'momence')->firstOrFail();
        $this->assertSame('the-cove', $momence->gym_id);
        $this->assertSame('prog-1', $momence->program_uuid);
        $this->assertSame($this->coach->id, $momence->linked_by_user_id);
        $this->assertNotNull($momence->linked_at);
        $this->assertSame('dev-a', $momence->device_id);
        $this->assertNull($momence->deleted_at);
        $this->assertNull($momence->spreadsheet_id);
        $this->assertNull($momence->sheet_id);
    }

    public function test_momence_track_is_required_and_must_be_peak_or_sa(): void
    {
        $this->pushDestinations([$this->momence(['momence_track' => null])])->assertStatus(422)->assertJson(['status' => 'error']);
        $this->pushDestinations([array_diff_key($this->momence(), ['momence_track' => true])])->assertStatus(422);
        $this->pushDestinations([$this->momence(['momence_track' => 'gold'])])->assertStatus(422);
        $this->pushDestinations([$this->momence(['momence_track' => 'PEAK'])])->assertStatus(422);

        $this->assertDatabaseCount('prism_program_destinations', 0);
    }

    public function test_momence_track_sa_is_accepted(): void
    {
        $this->pushDestinations([$this->momence(['momence_track' => 'sa'])])->assertStatus(200);

        $this->assertDatabaseCount('prism_program_destinations', 1);
    }

    public function test_momence_cycle_prefix_is_required_and_non_empty_after_trim(): void
    {
        $this->pushDestinations([array_diff_key($this->momence(), ['momence_cycle_prefix' => true])])->assertStatus(422);
        $this->pushDestinations([$this->momence(['momence_cycle_prefix' => ''])])->assertStatus(422);
        $this->pushDestinations([$this->momence(['momence_cycle_prefix' => '   '])])->assertStatus(422);
        $this->pushDestinations([$this->momence(['momence_cycle_prefix' => str_repeat('a', 256)])])->assertStatus(422);

        $this->assertDatabaseCount('prism_program_destinations', 0);
    }

    public function test_momence_bridge_prefix_is_required_and_non_empty_after_trim(): void
    {
        $this->pushDestinations([array_diff_key($this->momence(), ['momence_bridge_prefix' => true])])->assertStatus(422);
        $this->pushDestinations([$this->momence(['momence_bridge_prefix' => ''])])->assertStatus(422);
        $this->pushDestinations([$this->momence(['momence_bridge_prefix' => '   '])])->assertStatus(422);
        $this->pushDestinations([$this->momence(['momence_bridge_prefix' => str_repeat('a', 256)])])->assertStatus(422);

        $this->assertDatabaseCount('prism_program_destinations', 0);
    }

    public function test_momence_bridge_weeks_is_required_integer_at_least_one(): void
    {
        $this->pushDestinations([array_diff_key($this->momence(), ['momence_bridge_weeks' => true])])->assertStatus(422);
        $this->pushDestinations([$this->momence(['momence_bridge_weeks' => null])])->assertStatus(422);
        $this->pushDestinations([$this->momence(['momence_bridge_weeks' => 0])])->assertStatus(422);
        $this->pushDestinations([$this->momence(['momence_bridge_weeks' => -1])])->assertStatus(422);
        $this->pushDestinations([$this->momence(['momence_bridge_weeks' => 1.5])])->assertStatus(422);
        $this->pushDestinations([$this->momence(['momence_bridge_weeks' => 'two'])])->assertStatus(422);

        $this->assertDatabaseCount('prism_program_destinations', 0);
    }

    public function test_invalid_momence_item_refuses_the_whole_batch(): void
    {
        $this->pushDestinations([$this->sheet(), $this->momence(['momence_track' => null])])->assertStatus(422);

        $this->assertDatabaseCount('prism_program_destinations', 0);
    }

    public function test_sheets_upsert_does_not_need_momence_fields(): void
    {
        $this->pushDestinations([$this->sheet()])->assertStatus(200);

        $this->assertDatabaseCount('prism_program_destinations', 1);
    }

    public function test_momence_upsert_does_not_need_sheets_fields(): void
    {
        $this->pushDestinations([$this->momence()])->assertStatus(200);

        $this->assertDatabaseCount('prism_program_destinations', 1);
    }

    public function test_momence_bridge_weeks_is_cast_to_integer(): void
    {
        $destination = new PrismProgramDestination(['momence_bridge_weeks' => '3']);

        $this->assertSame(3, $destination->momence_bridge_weeks);
    }

    public function test_momence_columns_migration_runs_down_and_up(): void
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_11_11_120000_add_momence_fields_to_prism_program_destinations_table.php');
        $columns = ['momence_track', 'momence_cycle_prefix', 'momence_bridge_prefix', 'momence_bridge_weeks'];

        $this->assertTrue(Schema::hasColumns('prism_program_destinations', $columns));

        $migration->down();
        foreach ($columns as $column) {
            $this->assertFalse(Schema::hasColumn('prism_program_destinations', $column), $column);
        }
        $this->assertTrue(Schema::hasColumns('prism_program_destinations', ['spreadsheet_id', 'sheet_id', 'title']));

        $migration->up();
        $this->assertTrue(Schema::hasColumns('prism_program_destinations', $columns));
    }

    public function test_momence_upsert_stores_all_four_fields(): void
    {
        $this->pushDestinations([$this->momence(['momence_track' => 'sa'])])->assertStatus(200);

        $destination = PrismProgramDestination::where('type', 'momence')->firstOrFail();
        $this->assertSame('sa', $destination->momence_track);
        $this->assertSame('Peak Cycle', $destination->momence_cycle_prefix);
        $this->assertSame('Peak Bridge', $destination->momence_bridge_prefix);
        $this->assertSame(2, $destination->momence_bridge_weeks);
        $this->assertNull($destination->title);
    }

    public function test_each_type_stores_only_its_own_fields(): void
    {
        $momenceFields = array_diff_key($this->momence(), ['type' => true]);
        $sheetsFields = array_diff_key($this->sheet(), ['type' => true]);

        $this->pushDestinations([
            array_merge($this->sheet(), $momenceFields),
            array_merge($this->momence(), $sheetsFields),
        ])->assertStatus(200);

        $sheets = PrismProgramDestination::where('type', 'google_sheets')->firstOrFail();
        $this->assertSame('1AbC', $sheets->spreadsheet_id);
        $this->assertNull($sheets->momence_track);
        $this->assertNull($sheets->momence_cycle_prefix);
        $this->assertNull($sheets->momence_bridge_prefix);
        $this->assertNull($sheets->momence_bridge_weeks);

        $momence = PrismProgramDestination::where('type', 'momence')->firstOrFail();
        $this->assertSame('peak', $momence->momence_track);
        $this->assertNull($momence->spreadsheet_id);
        $this->assertNull($momence->sheet_id);
        $this->assertNull($momence->title);
    }

    public function test_resending_the_same_momence_values_changes_nothing(): void
    {
        $this->pushDestinations([$this->momence()])->assertStatus(200);
        $original = PrismProgramDestination::firstOrFail();

        $coachB = User::factory()->create(['name' => 'Coach B']);
        Sanctum::actingAs($coachB);
        Carbon::setTestNow(now()->addMinute());
        try {
            $this->pushDestinations([$this->momence()])->assertStatus(200);
        } finally {
            Carbon::setTestNow();
        }

        $destination = PrismProgramDestination::firstOrFail();
        $this->assertTrue($original->updated_at->eq($destination->updated_at));
        $this->assertTrue($original->linked_at->eq($destination->linked_at));
        $this->assertSame($this->coach->id, $destination->linked_by_user_id);
    }

    /**
     * @return array<string, array{string, string|int}>
     */
    public static function changedMomenceFieldProvider(): array
    {
        return [
            'track' => ['momence_track', 'sa'],
            'cycle prefix' => ['momence_cycle_prefix', 'Peak Cycle B'],
            'bridge prefix' => ['momence_bridge_prefix', 'Peak Bridge B'],
            'bridge weeks' => ['momence_bridge_weeks', 3],
        ];
    }

    #[DataProvider('changedMomenceFieldProvider')]
    public function test_changing_one_momence_field_bumps_updated_at_and_restamps_attribution(string $field, string|int $value): void
    {
        $this->pushDestinations([$this->momence()])->assertStatus(200);
        $original = PrismProgramDestination::firstOrFail();

        $coachB = User::factory()->create(['name' => 'Coach B']);
        Sanctum::actingAs($coachB);
        Carbon::setTestNow(now()->addMinute());
        try {
            $this->pushDestinations([$this->momence([$field => $value])])->assertStatus(200);
        } finally {
            Carbon::setTestNow();
        }

        $destination = PrismProgramDestination::firstOrFail();
        $this->assertSame($value, $destination->{$field});
        $this->assertTrue($destination->updated_at->gt($original->updated_at));
        $this->assertSame($coachB->id, $destination->linked_by_user_id);
        $this->assertTrue($destination->linked_at->gt($original->linked_at));
    }

    public function test_removing_momence_soft_deletes_only_that_row(): void
    {
        $this->pushDestinations([$this->sheet(), $this->momence()])->assertStatus(200);

        $this->pushDestinations([], [['type' => 'momence']])->assertStatus(200);

        $this->assertDatabaseCount('prism_program_destinations', 2);
        $momence = PrismProgramDestination::where('type', 'momence')->firstOrFail();
        $this->assertNotNull($momence->deleted_at);
        $this->assertSame('peak', $momence->momence_track, 'tombstone keeps the row for other devices');
        $this->assertNull(PrismProgramDestination::where('type', 'google_sheets')->firstOrFail()->deleted_at);
    }
}
