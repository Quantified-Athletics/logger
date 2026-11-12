<?php

namespace Tests\Feature\Sync\Prism;

use App\Models\User;
use App\Sync\Models\Gym;
use App\Sync\Models\PrismExerciseCap;
use App\Sync\Models\PrismExerciseFamily;
use App\Sync\Models\PrismExerciseFamilyMember;
use App\Sync\Models\PrismExerciseOverlayRecord;
use App\Sync\Models\PrismPreference;
use App\Sync\Models\PrismProgram;
use App\Sync\Models\PrismProgramCell;
use App\Sync\Models\PrismProgramDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PrismChangesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Gym::create(['id' => 'the-cove', 'name' => 'The Cove']);
    }

    public function test_cold_start_returns_full_gym_state(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $tz = config('app.timezone');
        $t = Carbon::parse('2026-09-01 12:00:00', $tz);

        $program = PrismProgram::create([
            'gym_id' => 'the-cove',
            'program_uuid' => 'prog-1',
            'name' => 'Strength Cycle',
            'week_count' => 4,
            'start_date' => '2026-11-02',
            'device_id' => 'dev-1',
        ]);
        PrismProgram::where('id', $program->id)->update(['updated_at' => $t]);

        $cell1 = PrismProgramCell::create([
            'gym_id' => 'the-cove',
            'program_uuid' => 'prog-1',
            'cell_id' => 'w1d1',
            'text' => 'Squat 5x5',
            'device_id' => 'dev-1',
        ]);
        PrismProgramCell::where('id', $cell1->id)->update(['updated_at' => $t]);

        $cell2 = PrismProgramCell::create([
            'gym_id' => 'the-cove',
            'program_uuid' => 'prog-1',
            'cell_id' => 'w1d2',
            'text' => 'Bench 5x5',
            'device_id' => 'dev-1',
        ]);
        PrismProgramCell::where('id', $cell2->id)->update(['updated_at' => $t]);

        $overlay = PrismExerciseOverlayRecord::create([
            'gym_id' => 'the-cove',
            'exercise_id' => 'back-squat',
            'record' => ['custom_notes' => 'Low bar'],
            'device_id' => 'dev-1',
            'deleted_at' => null,
        ]);
        PrismExerciseOverlayRecord::where('id', $overlay->id)->update(['updated_at' => $t]);

        $pref = PrismPreference::create([
            'gym_id' => 'the-cove',
            'data' => ['theme' => 'dark'],
            'device_id' => 'dev-1',
        ]);
        PrismPreference::where('id', $pref->id)->update(['updated_at' => $t]);

        $response = $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->getJson('/api/sync/prism/the-cove/changes');

        $response->assertStatus(200)
            ->assertJsonStructure(['status', 'cursor', 'programs', 'cells', 'exercises', 'preferences', 'dismissals', 'destinations']);

        $this->assertEquals('ok', $response->json('status'));
        $this->assertEquals($t->toIso8601String(), $response->json('cursor'));

        $programs = $response->json('programs');
        $this->assertCount(1, $programs);
        $this->assertEquals([
            'program_uuid' => 'prog-1',
            'name' => 'Strength Cycle',
            'week_count' => 4,
            'start_date' => '2026-11-02',
            'archived_at' => null,
            'tab_position' => null,
            'updated_at' => $t->toIso8601String(),
        ], $programs[0]);

        $cells = $response->json('cells');
        $this->assertCount(2, $cells);

        $exercises = $response->json('exercises');
        $this->assertCount(1, $exercises);
        $this->assertEquals([
            'exercise_id' => 'back-squat',
            'record' => ['custom_notes' => 'Low bar'],
            'deleted_at' => null,
            'updated_at' => $t->toIso8601String(),
        ], $exercises[0]);

        $this->assertEquals([
            'data' => ['theme' => 'dark'],
            'updated_at' => $t->toIso8601String(),
        ], $response->json('preferences'));
    }

    public function test_since_filters_to_rows_at_or_after_cursor(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $tz = config('app.timezone');
        $tBefore = Carbon::parse('2026-09-01 10:00:00', $tz);
        $tSince = Carbon::parse('2026-09-01 12:00:00', $tz);
        $tAfter = Carbon::parse('2026-09-01 14:00:00', $tz);

        // Programs: 1 before, 1 after
        $progOld = PrismProgram::create([
            'gym_id' => 'the-cove',
            'program_uuid' => 'prog-old',
            'name' => 'Old Program',
            'week_count' => 1,
        ]);
        PrismProgram::where('id', $progOld->id)->update(['updated_at' => $tBefore]);

        $progNew = PrismProgram::create([
            'gym_id' => 'the-cove',
            'program_uuid' => 'prog-new',
            'name' => 'New Program',
            'week_count' => 2,
        ]);
        PrismProgram::where('id', $progNew->id)->update(['updated_at' => $tAfter]);

        // Cells: 1 before, 1 at since, 1 after
        $cellOld = PrismProgramCell::create([
            'gym_id' => 'the-cove',
            'program_uuid' => 'prog-old',
            'cell_id' => 'w1d1',
            'text' => 'Old cell',
        ]);
        PrismProgramCell::where('id', $cellOld->id)->update(['updated_at' => $tBefore]);

        $cellAt = PrismProgramCell::create([
            'gym_id' => 'the-cove',
            'program_uuid' => 'prog-new',
            'cell_id' => 'w1d1',
            'text' => 'Cell at since',
        ]);
        PrismProgramCell::where('id', $cellAt->id)->update(['updated_at' => $tSince]);

        $cellNew = PrismProgramCell::create([
            'gym_id' => 'the-cove',
            'program_uuid' => 'prog-new',
            'cell_id' => 'w1d2',
            'text' => 'Cell after since',
        ]);
        PrismProgramCell::where('id', $cellNew->id)->update(['updated_at' => $tAfter]);

        // Exercises: 1 before, 1 after
        $exOld = PrismExerciseOverlayRecord::create([
            'gym_id' => 'the-cove',
            'exercise_id' => 'bench-press',
            'record' => ['grip' => 'narrow'],
            'deleted_at' => null,
        ]);
        PrismExerciseOverlayRecord::where('id', $exOld->id)->update(['updated_at' => $tBefore]);

        $exNew = PrismExerciseOverlayRecord::create([
            'gym_id' => 'the-cove',
            'exercise_id' => 'deadlift',
            'record' => ['stance' => 'sumo'],
            'deleted_at' => null,
        ]);
        PrismExerciseOverlayRecord::where('id', $exNew->id)->update(['updated_at' => $tAfter]);

        // Preferences: stamped before since
        $pref = PrismPreference::create([
            'gym_id' => 'the-cove',
            'data' => ['theme' => 'light'],
        ]);
        PrismPreference::where('id', $pref->id)->update(['updated_at' => $tBefore]);

        $response = $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->getJson('/api/sync/prism/the-cove/changes?since=' . urlencode($tSince->toIso8601String()));

        $response->assertStatus(200);

        $programs = $response->json('programs');
        $this->assertCount(1, $programs);
        $this->assertEquals('prog-new', $programs[0]['program_uuid']);

        $cells = $response->json('cells');
        $this->assertCount(2, $cells);
        $cellIds = array_column($cells, 'cell_id');
        $this->assertContains('w1d1', $cellIds);
        $this->assertContains('w1d2', $cellIds);

        $exercises = $response->json('exercises');
        $this->assertCount(1, $exercises);
        $this->assertEquals('deadlift', $exercises[0]['exercise_id']);

        $this->assertNull($response->json('preferences'));
    }

    public function test_inclusive_boundary_delivers_two_writes_at_same_instant(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $t = Carbon::parse('2026-09-01T12:00:00.000001Z');

        $cell1 = PrismProgramCell::create([
            'gym_id' => 'the-cove',
            'program_uuid' => 'prog-sync',
            'cell_id' => 'w1d1',
            'text' => 'Cell 1 content',
        ]);
        PrismProgramCell::where('id', $cell1->id)->update(['updated_at' => $t]);

        $cell2 = PrismProgramCell::create([
            'gym_id' => 'the-cove',
            'program_uuid' => 'prog-sync',
            'cell_id' => 'w1d2',
            'text' => 'Cell 2 content',
        ]);
        PrismProgramCell::where('id', $cell2->id)->update(['updated_at' => $t]);

        $response = $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->getJson('/api/sync/prism/the-cove/changes?since=' . urlencode($t->toIso8601String()));

        $response->assertStatus(200);

        $cells = $response->json('cells');
        $this->assertCount(2, $cells);

        $cellIds = array_column($cells, 'cell_id');
        $this->assertContains('w1d1', $cellIds);
        $this->assertContains('w1d2', $cellIds);
    }

    public function test_tombstone_is_delivered_in_delta(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $tz = config('app.timezone');
        $tSince = Carbon::parse('2026-09-01 12:00:00', $tz);
        $tTombstone = Carbon::parse('2026-09-01 14:00:00', $tz);

        $record = PrismExerciseOverlayRecord::create([
            'gym_id' => 'the-cove',
            'exercise_id' => 'removed-lift',
            'record' => null,
            'device_id' => 'dev-tombstone',
            'deleted_at' => $tTombstone,
        ]);
        PrismExerciseOverlayRecord::where('id', $record->id)->update([
            'updated_at' => $tTombstone,
            'deleted_at' => $tTombstone,
        ]);

        $response = $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->getJson('/api/sync/prism/the-cove/changes?since=' . urlencode($tSince->toIso8601String()));

        $response->assertStatus(200);

        $exercises = $response->json('exercises');
        $this->assertCount(1, $exercises);
        $this->assertEquals('removed-lift', $exercises[0]['exercise_id']);
        $this->assertNull($exercises[0]['record']);
        $this->assertEquals($tTombstone->toIso8601String(), $exercises[0]['deleted_at']);
        $this->assertEquals($tTombstone->toIso8601String(), $exercises[0]['updated_at']);
    }

    public function test_cursor_is_non_rewinding_high_water_mark(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $tz = config('app.timezone');
        $t1 = Carbon::parse('2026-09-01 10:00:00', $tz);
        $t2 = Carbon::parse('2026-09-01 15:00:00', $tz);

        $cell = PrismProgramCell::create([
            'gym_id' => 'the-cove',
            'program_uuid' => 'prog-1',
            'cell_id' => 'w1d1',
            'text' => 'Content',
        ]);
        PrismProgramCell::where('id', $cell->id)->update(['updated_at' => $t1]);

        $overlay = PrismExerciseOverlayRecord::create([
            'gym_id' => 'the-cove',
            'exercise_id' => 'deadlift',
            'record' => null,
            'deleted_at' => $t2,
        ]);
        PrismExerciseOverlayRecord::where('id', $overlay->id)->update([
            'updated_at' => $t1,
            'deleted_at' => $t2,
        ]);

        // Cold start: cursor = max(updated_at, deleted_at) = t2
        $response1 = $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->getJson('/api/sync/prism/the-cove/changes');
        $response1->assertStatus(200);
        $this->assertEquals($t2->toIso8601String(), $response1->json('cursor'));

        // Query with since later than all rows: cursor stays at since (never rewinds)
        $tLater = Carbon::parse('2026-09-01 18:00:00', $tz);
        $response2 = $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->getJson('/api/sync/prism/the-cove/changes?since=' . urlencode($tLater->toIso8601String()));
        $response2->assertStatus(200);
        $this->assertEquals($tLater->toIso8601String(), $response2->json('cursor'));
    }

    public function test_empty_gym_returns_empty_collections(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        // Cold start with empty gym
        $response = $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->getJson('/api/sync/prism/the-cove/changes');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
                'programs' => [],
                'cells' => [],
                'exercises' => [],
                'preferences' => null,
            ]);
        $this->assertNotNull($response->json('cursor'));

        // With since on empty gym
        $tz = config('app.timezone');
        $tSince = Carbon::parse('2026-09-01 12:00:00', $tz);
        $responseSince = $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->getJson('/api/sync/prism/the-cove/changes?since=' . urlencode($tSince->toIso8601String()));

        $responseSince->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
                'cursor' => $tSince->toIso8601String(),
                'programs' => [],
                'cells' => [],
                'exercises' => [],
                'preferences' => null,
            ]);
    }

    public function test_unknown_gym_returns_404(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->getJson('/api/sync/prism/nonexistent/changes');

        $response->assertStatus(404)
            ->assertJson([
                'status' => 'error',
                'message' => 'Gym not found.',
            ]);
    }

    public function test_unauthenticated_returns_401(): void
    {
        $response = $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->getJson('/api/sync/prism/the-cove/changes');

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_invalid_since_returns_422(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->getJson('/api/sync/prism/the-cove/changes?since=not-a-date');

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
            ]);
    }

    public function test_changes_query_count_is_flat_under_growing_data(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        // 1. Seed small dataset: 1 program, 2 cells, 2 exercise overlays, 1 preferences row
        PrismProgram::create([
            'gym_id' => 'the-cove',
            'program_uuid' => 'prog-small-1',
            'name' => 'Small Program',
            'week_count' => 1,
            'start_date' => '2026-11-02',
            'device_id' => 'dev-1',
        ]);

        PrismProgramDestination::create([
            'gym_id' => 'the-cove',
            'program_uuid' => 'prog-small-1',
            'type' => 'google_sheets',
            'spreadsheet_id' => 'sheet-small',
            'sheet_id' => 0,
            'linked_by_user_id' => $user->id,
        ]);

        for ($i = 1; $i <= 2; $i++) {
            PrismProgramCell::create([
                'gym_id' => 'the-cove',
                'program_uuid' => 'prog-small-1',
                'cell_id' => "w1d{$i}",
                'text' => "Exercise {$i}",
                'device_id' => 'dev-1',
            ]);
        }

        for ($i = 1; $i <= 2; $i++) {
            PrismExerciseOverlayRecord::create([
                'gym_id' => 'the-cove',
                'exercise_id' => "exercise-small-{$i}",
                'record' => ['name' => "Custom {$i}"],
                'device_id' => 'dev-1',
                'deleted_at' => null,
            ]);
        }

        PrismPreference::create([
            'gym_id' => 'the-cove',
            'data' => ['color' => 'blue'],
            'device_id' => 'dev-1',
        ]);

        for ($i = 1; $i <= 2; $i++) {
            PrismExerciseFamily::create([
                'gym_id' => 'the-cove',
                'family_id' => "small-family-{$i}",
                'name' => "Small family {$i}",
                'device_id' => 'dev-1',
            ]);
            PrismExerciseFamilyMember::create([
                'gym_id' => 'the-cove',
                'family_id' => 'small-family-1',
                'exercise_id' => "small-member-{$i}",
                'is_member' => true,
                'device_id' => 'dev-1',
            ]);
            PrismExerciseCap::create([
                'gym_id' => 'the-cove',
                'exercise_id' => "small-cap-{$i}",
                'cap_count' => 1,
                'cap_period' => 'week',
                'device_id' => 'dev-1',
            ]);
        }

        \Illuminate\Support\Facades\DB::flushQueryLog();
        $responseSmall = $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->getJson('/api/sync/prism/the-cove/changes');
        $smallCount = count(\Illuminate\Support\Facades\DB::getQueryLog());

        $responseSmall->assertStatus(200);

        // 2. Seed large dataset into the same gym: 10 programs, 50 cells, 30 overlays
        for ($p = 1; $p <= 10; $p++) {
            PrismProgram::create([
                'gym_id' => 'the-cove',
                'program_uuid' => "prog-large-{$p}",
                'name' => "Large Program {$p}",
                'week_count' => 5,
                'start_date' => '2026-11-02',
                'device_id' => 'dev-1',
            ]);

            // Each destination linked by a different user: an N+1 on linked_by would show up here.
            PrismProgramDestination::create([
                'gym_id' => 'the-cove',
                'program_uuid' => "prog-large-{$p}",
                'type' => 'google_sheets',
                'spreadsheet_id' => 'sheet-large',
                'sheet_id' => $p,
                'linked_by_user_id' => User::factory()->create()->id,
            ]);
        }

        for ($c = 1; $c <= 50; $c++) {
            $progIndex = ($c % 10) + 1;
            PrismProgramCell::create([
                'gym_id' => 'the-cove',
                'program_uuid' => "prog-large-{$progIndex}",
                'cell_id' => "w1d{$c}",
                'text' => "Cell text {$c}",
                'device_id' => 'dev-1',
            ]);
        }

        for ($e = 1; $e <= 30; $e++) {
            PrismExerciseOverlayRecord::create([
                'gym_id' => 'the-cove',
                'exercise_id' => "exercise-large-{$e}",
                'record' => ['name' => "Custom Overlay {$e}"],
                'device_id' => 'dev-1',
                'deleted_at' => ($e % 5 === 0) ? now() : null,
            ]);
            PrismExerciseFamily::create([
                'gym_id' => 'the-cove',
                'family_id' => "large-family-{$e}",
                'name' => "Large family {$e}",
                'device_id' => 'dev-1',
                'deleted_at' => ($e % 5 === 0) ? now() : null,
            ]);
            PrismExerciseFamilyMember::create([
                'gym_id' => 'the-cove',
                'family_id' => 'large-family-1',
                'exercise_id' => "large-member-{$e}",
                'is_member' => true,
                'device_id' => 'dev-1',
                'deleted_at' => ($e % 5 === 0) ? now() : null,
            ]);
            PrismExerciseCap::create([
                'gym_id' => 'the-cove',
                'exercise_id' => "large-cap-{$e}",
                'cap_count' => 2,
                'cap_period' => 'program',
                'device_id' => 'dev-1',
                'deleted_at' => ($e % 5 === 0) ? now() : null,
            ]);
        }

        \Illuminate\Support\Facades\DB::flushQueryLog();
        $responseLarge = $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->getJson('/api/sync/prism/the-cove/changes');
        $largeCount = count(\Illuminate\Support\Facades\DB::getQueryLog());

        $responseLarge->assertStatus(200);

        // 3. Assert counts are equal and small (<= 31; destinations add 4 constant queries:
        //    rows, eager-loaded linked_by users, max updated_at, max deleted_at; comments add 3:
        //    rows, eager-loaded authors, max updated_at; the exercise family, member and cap
        //    tables add 9 more: rows, max updated_at, max deleted_at for each)
        $this->assertSame($smallCount, $largeCount);
        $this->assertLessThanOrEqual(31, $smallCount);
    }

    public function test_changes_programs_carry_archived_at_and_archive_appears_in_since_delta(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $tz = config('app.timezone');
        $t1 = Carbon::parse('2026-09-01 10:00:00', $tz);
        $tSince = Carbon::parse('2026-09-01 12:00:00', $tz);
        $tArchive = Carbon::parse('2026-09-01 14:00:00', $tz);

        $program = PrismProgram::create([
            'gym_id' => 'the-cove',
            'program_uuid' => 'prog-archived',
            'name' => 'To Be Archived',
            'week_count' => 4,
            'archived_at' => null,
        ]);
        PrismProgram::where('id', $program->id)->update(['updated_at' => $t1]);

        // Cold start shows program with archived_at = null
        $responseCold = $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->getJson('/api/sync/prism/the-cove/changes');
        $responseCold->assertStatus(200);
        $this->assertCount(1, $responseCold->json('programs'));
        $this->assertNull($responseCold->json('programs.0.archived_at'));

        // Query with since: program updated at t1 is excluded
        $responseSinceBefore = $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->getJson('/api/sync/prism/the-cove/changes?since=' . urlencode($tSince->toIso8601String()));
        $responseSinceBefore->assertStatus(200);
        $this->assertCount(0, $responseSinceBefore->json('programs'));

        // Archive the program (simulating upsert action or update) bumping updated_at to tArchive
        $program->update([
            'archived_at' => $tArchive,
        ]);
        PrismProgram::where('id', $program->id)->update(['updated_at' => $tArchive]);

        // Now query with since: program appears in delta with ISO-8601 archived_at
        $responseSinceAfter = $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->getJson('/api/sync/prism/the-cove/changes?since=' . urlencode($tSince->toIso8601String()));
        $responseSinceAfter->assertStatus(200);
        $programs = $responseSinceAfter->json('programs');
        $this->assertCount(1, $programs);
        $this->assertSame('prog-archived', $programs[0]['program_uuid']);
        $this->assertSame($tArchive->toIso8601String(), $programs[0]['archived_at']);
        $this->assertSame($tArchive->toIso8601String(), $programs[0]['updated_at']);
    }

    public function test_changes_programs_carry_tab_position_and_a_move_appears_in_since_delta(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $tz = config('app.timezone');
        $t1 = Carbon::parse('2026-09-01 10:00:00', $tz);
        $tSince = Carbon::parse('2026-09-01 12:00:00', $tz);
        $tMove = Carbon::parse('2026-09-01 14:00:00', $tz);

        $tabbed = PrismProgram::create([
            'gym_id' => 'the-cove',
            'program_uuid' => 'prog-tabbed',
            'name' => 'Tabbed',
            'week_count' => 4,
            'tab_position' => 'a0V',
        ]);
        $untabbed = PrismProgram::create([
            'gym_id' => 'the-cove',
            'program_uuid' => 'prog-untabbed',
            'name' => 'Untabbed',
            'week_count' => 4,
        ]);
        PrismProgram::whereIn('id', [$tabbed->id, $untabbed->id])->update(['updated_at' => $t1]);

        $cold = $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->getJson('/api/sync/prism/the-cove/changes');
        $cold->assertStatus(200);
        $coldPrograms = collect($cold->json('programs'))->keyBy('program_uuid');
        $this->assertSame('a0V', $coldPrograms['prog-tabbed']['tab_position']);
        $this->assertArrayHasKey('tab_position', $coldPrograms['prog-untabbed']);
        $this->assertNull($coldPrograms['prog-untabbed']['tab_position']);

        Carbon::setTestNow($tMove);
        try {
            $this->withHeaders(['X-Device-Id' => 'dev-1'])
                ->postJson('/api/sync/prism/the-cove/programs/prog-untabbed', ['tab_position' => 'b'])
                ->assertStatus(200);
        } finally {
            Carbon::setTestNow();
        }

        $delta = $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->getJson('/api/sync/prism/the-cove/changes?since=' . urlencode($tSince->toIso8601String()));
        $delta->assertStatus(200);
        $programs = $delta->json('programs');
        $this->assertCount(1, $programs);
        $this->assertSame('prog-untabbed', $programs[0]['program_uuid']);
        $this->assertSame('b', $programs[0]['tab_position']);
    }

    public function test_cell_tombstone_is_delivered_in_delta(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $tz = config('app.timezone');
        $tSince = Carbon::parse('2026-09-01 12:00:00', $tz);
        $tTombstone = Carbon::parse('2026-09-01 14:00:00', $tz);

        $cell = PrismProgramCell::create([
            'gym_id' => 'the-cove',
            'program_uuid' => 'prog-del',
            'cell_id' => 'w1d1',
            'text' => '',
            'device_id' => 'dev-tombstone',
            'deleted_at' => $tTombstone,
        ]);
        PrismProgramCell::where('id', $cell->id)->update([
            'updated_at' => $tTombstone,
            'deleted_at' => $tTombstone,
        ]);

        $response = $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->getJson('/api/sync/prism/the-cove/changes?since=' . urlencode($tSince->toIso8601String()));

        $response->assertStatus(200);

        $cells = $response->json('cells');
        $this->assertCount(1, $cells);
        $this->assertEquals('w1d1', $cells[0]['cell_id']);
        $this->assertEquals('prog-del', $cells[0]['program_uuid']);
        $this->assertEquals($tTombstone->toIso8601String(), $cells[0]['deleted_at']);
        $this->assertEquals($tTombstone->toIso8601String(), $cells[0]['updated_at']);
    }

    public function test_live_cell_carries_null_deleted_at(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        PrismProgramCell::create([
            'gym_id' => 'the-cove',
            'program_uuid' => 'prog-live',
            'cell_id' => 'w1d1',
            'text' => 'Back Squat 3x5',
            'device_id' => 'dev-1',
        ]);

        $response = $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->getJson('/api/sync/prism/the-cove/changes');

        $response->assertStatus(200);
        $cells = $response->json('cells');
        $this->assertCount(1, $cells);
        $this->assertArrayHasKey('deleted_at', $cells[0]);
        $this->assertNull($cells[0]['deleted_at']);
    }

    public function test_cursor_advances_on_cell_removal(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $tz = config('app.timezone');
        $t1 = Carbon::parse('2026-09-01 10:00:00', $tz);
        $tTombstone = Carbon::parse('2026-09-01 15:00:00', $tz);

        // A live cell updated at t1
        $liveCell = PrismProgramCell::create([
            'gym_id' => 'the-cove',
            'program_uuid' => 'prog-1',
            'cell_id' => 'w1d1',
            'text' => 'Content',
            'device_id' => 'dev-1',
        ]);
        PrismProgramCell::where('id', $liveCell->id)->update(['updated_at' => $t1]);

        // A tombstoned cell whose deleted_at is the latest instant in the gym
        $deadCell = PrismProgramCell::create([
            'gym_id' => 'the-cove',
            'program_uuid' => 'prog-1',
            'cell_id' => 'w1d2',
            'text' => '',
            'device_id' => 'dev-1',
            'deleted_at' => $tTombstone,
        ]);
        PrismProgramCell::where('id', $deadCell->id)->update([
            'updated_at' => $t1,
            'deleted_at' => $tTombstone,
        ]);

        // Cold start: cursor = max(updated_at, deleted_at) = the tombstone instant
        $response = $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->getJson('/api/sync/prism/the-cove/changes');

        $response->assertStatus(200);
        $this->assertEquals($tTombstone->toIso8601String(), $response->json('cursor'));
    }

    public function test_changes_carry_destinations_with_linked_by_and_tombstones_since_cursor(): void
    {
        $coach = User::factory()->create(['name' => 'Coach A']);
        Sanctum::actingAs($coach, ['*']);

        $tz = config('app.timezone');
        $tOld = Carbon::parse('2026-10-01 12:00:00', $tz);
        $tLink = Carbon::parse('2026-10-31 15:04:00', $tz);
        $tUnlink = Carbon::parse('2026-10-31 16:00:00', $tz);

        $old = PrismProgramDestination::create([
            'gym_id' => 'the-cove',
            'program_uuid' => 'prog-old',
            'type' => 'google_sheets',
            'spreadsheet_id' => 'sheet-old',
            'sheet_id' => 0,
        ]);
        PrismProgramDestination::where('id', $old->id)->update(['updated_at' => $tOld]);

        $linked = PrismProgramDestination::create([
            'gym_id' => 'the-cove',
            'program_uuid' => 'prog-1',
            'type' => 'google_sheets',
            'spreadsheet_id' => '1AbC',
            'sheet_id' => 0,
            'title' => 'Spring Block 2026',
            'linked_by_user_id' => $coach->id,
            'linked_at' => $tLink,
        ]);
        PrismProgramDestination::where('id', $linked->id)->update(['updated_at' => $tLink]);

        $unlinked = PrismProgramDestination::create([
            'gym_id' => 'the-cove',
            'program_uuid' => 'prog-2',
            'type' => 'google_sheets',
            'spreadsheet_id' => '1AbC',
            'sheet_id' => 5,
            'linked_by_user_id' => null,
            'deleted_at' => $tUnlink,
        ]);
        PrismProgramDestination::where('id', $unlinked->id)->update(['updated_at' => $tUnlink]);

        $response = $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->getJson('/api/sync/prism/the-cove/changes?since=' . urlencode($tLink->toIso8601String()));

        $response->assertStatus(200);
        $this->assertSame($tUnlink->toIso8601String(), $response->json('cursor'));

        $destinations = collect($response->json('destinations'))->keyBy('program_uuid');
        $this->assertSame(['prog-1', 'prog-2'], $destinations->keys()->sort()->values()->all());

        $this->assertEquals([
            'program_uuid' => 'prog-1',
            'type' => 'google_sheets',
            'spreadsheet_id' => '1AbC',
            'sheet_id' => 0,
            'title' => 'Spring Block 2026',
            'momence_track' => null,
            'momence_cycle_prefix' => null,
            'momence_bridge_prefix' => null,
            'momence_bridge_weeks' => null,
            'linked_by' => ['id' => $coach->id, 'name' => 'Coach A'],
            'linked_at' => $tLink->toIso8601String(),
            'deleted_at' => null,
            'updated_at' => $tLink->toIso8601String(),
        ], $destinations['prog-1']);

        $this->assertNull($destinations['prog-2']['linked_by']);
        $this->assertSame($tUnlink->toIso8601String(), $destinations['prog-2']['deleted_at']);
    }

    public function test_changes_carry_momence_destination_with_all_four_fields(): void
    {
        $coach = User::factory()->create(['name' => 'Coach A']);
        Sanctum::actingAs($coach, ['*']);

        $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->postJson('/api/sync/prism/the-cove/programs/prog-1/destinations', [
                'upserts' => [
                    ['type' => 'google_sheets', 'spreadsheet_id' => '1AbC', 'sheet_id' => 0, 'title' => 'Spring'],
                    [
                        'type' => 'momence',
                        'momence_track' => 'sa',
                        'momence_cycle_prefix' => 'SA Cycle',
                        'momence_bridge_prefix' => 'SA Bridge',
                        'momence_bridge_weeks' => 2,
                    ],
                ],
                'removals' => [],
            ])
            ->assertStatus(200);

        $response = $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->getJson('/api/sync/prism/the-cove/changes');

        $response->assertStatus(200);
        $destinations = collect($response->json('destinations'))->keyBy('type');

        $momence = $destinations['momence'];
        $this->assertSame('prog-1', $momence['program_uuid']);
        $this->assertSame('sa', $momence['momence_track']);
        $this->assertSame('SA Cycle', $momence['momence_cycle_prefix']);
        $this->assertSame('SA Bridge', $momence['momence_bridge_prefix']);
        $this->assertSame(2, $momence['momence_bridge_weeks']);
        $this->assertNull($momence['spreadsheet_id']);
        $this->assertNull($momence['sheet_id']);
        $this->assertNull($momence['title']);
        $this->assertSame(['id' => $coach->id, 'name' => 'Coach A'], $momence['linked_by']);
        $this->assertNull($momence['deleted_at']);

        $sheets = $destinations['google_sheets'];
        foreach (['momence_track', 'momence_cycle_prefix', 'momence_bridge_prefix', 'momence_bridge_weeks'] as $field) {
            $this->assertArrayHasKey($field, $sheets);
            $this->assertNull($sheets[$field], $field);
        }
    }

    public function test_changes_programs_carry_start_date_and_no_metadata(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        PrismProgram::create([
            'gym_id' => 'the-cove',
            'program_uuid' => 'prog-dated',
            'name' => 'Dated',
            'week_count' => 4,
            'start_date' => '2026-11-02',
        ]);
        PrismProgram::create([
            'gym_id' => 'the-cove',
            'program_uuid' => 'prog-undated',
            'name' => 'Undated',
            'week_count' => 4,
        ]);

        $programs = collect($this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->getJson('/api/sync/prism/the-cove/changes')
            ->assertStatus(200)
            ->json('programs'))->keyBy('program_uuid');

        $this->assertSame('2026-11-02', $programs['prog-dated']['start_date']);
        $this->assertNull($programs['prog-undated']['start_date']);
        $this->assertArrayNotHasKey('metadata', $programs['prog-dated']);
    }

    public function test_changes_return_each_cells_workout_uuid(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        PrismProgramCell::create([
            'gym_id' => 'the-cove',
            'program_uuid' => 'prog-id',
            'cell_id' => 'w1d1',
            'workout_uuid' => '7d9f2c1e-8a4b-4c3d-9e2f-1a2b3c4d5e6f',
            'text' => 'Back Squat 3x5',
            'device_id' => 'dev-1',
        ]);
        PrismProgramCell::create([
            'gym_id' => 'the-cove',
            'program_uuid' => 'prog-id',
            'cell_id' => 'w1d2',
            'text' => 'Bench Press 3x5',
            'device_id' => 'dev-1',
        ]);

        $cells = collect($this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->getJson('/api/sync/prism/the-cove/changes')
            ->assertStatus(200)
            ->json('cells'))->keyBy('cell_id');

        $this->assertEquals('7d9f2c1e-8a4b-4c3d-9e2f-1a2b3c4d5e6f', $cells['w1d1']['workout_uuid']);
        $this->assertArrayHasKey('workout_uuid', $cells['w1d2']);
        $this->assertNull($cells['w1d2']['workout_uuid']);
    }

    /**
     * @return array<string, array{0: class-string, 1: array<string, mixed>, 2: string}>
     */
    public static function exerciseLimitRows(): array
    {
        return [
            'family' => [PrismExerciseFamily::class, ['gym_id' => 'the-cove', 'family_id' => 'burpees', 'name' => 'Burpees', 'cap_count' => 2, 'cap_period' => 'week', 'device_id' => 'dev-1'], 'exercise_families'],
            'member' => [PrismExerciseFamilyMember::class, ['gym_id' => 'the-cove', 'exercise_id' => 'clean-and-jerk', 'family_id' => 'clean', 'is_member' => true, 'device_id' => 'dev-1'], 'exercise_family_members'],
            'cap' => [PrismExerciseCap::class, ['gym_id' => 'the-cove', 'exercise_id' => 'burpee-box-jump', 'cap_count' => 1, 'cap_period' => 'program', 'device_id' => 'dev-1'], 'exercise_caps'],
        ];
    }

    /**
     * @param  class-string  $modelClass
     * @param  array<string, mixed>  $attributes
     */
    private function createAtTime(string $modelClass, array $attributes, string $updatedAt): void
    {
        $row = $modelClass::create($attributes);
        $modelClass::where('id', $row->id)->update(['updated_at' => $updatedAt]);
    }

    public function test_changes_carry_exercise_limit_rows_with_exact_shapes(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $tz = config('app.timezone');
        $t1 = Carbon::parse('2026-09-01 10:00:00', $tz)->toIso8601String();

        $this->createAtTime(PrismExerciseFamily::class, ['gym_id' => 'the-cove', 'family_id' => 'burpees', 'name' => 'Burpees', 'cap_count' => 2, 'cap_period' => 'week', 'device_id' => 'dev-1'], '2026-09-01 10:00:00');
        $this->createAtTime(PrismExerciseFamilyMember::class, ['gym_id' => 'the-cove', 'exercise_id' => 'clean-and-jerk', 'family_id' => 'clean', 'is_member' => true, 'device_id' => 'dev-1'], '2026-09-01 10:00:00');
        $this->createAtTime(PrismExerciseCap::class, ['gym_id' => 'the-cove', 'exercise_id' => 'burpee-box-jump', 'cap_count' => 1, 'cap_period' => 'program', 'device_id' => 'dev-1'], '2026-09-01 10:00:00');

        $response = $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->getJson('/api/sync/prism/the-cove/changes');

        $response->assertStatus(200);
        $this->assertSame([
            'family_id' => 'burpees',
            'name' => 'Burpees',
            'cap_count' => 2,
            'cap_period' => 'week',
            'deleted_at' => null,
            'updated_at' => $t1,
        ], $response->json('exercise_families.0'));
        $this->assertSame([
            'exercise_id' => 'clean-and-jerk',
            'family_id' => 'clean',
            'is_member' => true,
            'deleted_at' => null,
            'updated_at' => $t1,
        ], $response->json('exercise_family_members.0'));
        $this->assertSame([
            'exercise_id' => 'burpee-box-jump',
            'cap_count' => 1,
            'cap_period' => 'program',
            'deleted_at' => null,
            'updated_at' => $t1,
        ], $response->json('exercise_caps.0'));
    }

    public function test_empty_gym_returns_empty_exercise_limit_lists(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $response = $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->getJson('/api/sync/prism/the-cove/changes');

        $response->assertStatus(200);
        $this->assertSame([], $response->json('exercise_families'));
        $this->assertSame([], $response->json('exercise_family_members'));
        $this->assertSame([], $response->json('exercise_caps'));
    }

    public function test_since_filters_exercise_limit_rows(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->createAtTime(PrismExerciseFamily::class, ['gym_id' => 'the-cove', 'family_id' => 'old-family', 'name' => 'Old', 'device_id' => 'dev-1'], '2026-09-01 10:00:00');
        $this->createAtTime(PrismExerciseFamily::class, ['gym_id' => 'the-cove', 'family_id' => 'new-family', 'name' => 'New', 'device_id' => 'dev-1'], '2026-09-01 12:00:00');
        $this->createAtTime(PrismExerciseFamilyMember::class, ['gym_id' => 'the-cove', 'exercise_id' => 'old-member', 'family_id' => 'f', 'is_member' => true, 'device_id' => 'dev-1'], '2026-09-01 10:00:00');
        $this->createAtTime(PrismExerciseFamilyMember::class, ['gym_id' => 'the-cove', 'exercise_id' => 'new-member', 'family_id' => 'f', 'is_member' => true, 'device_id' => 'dev-1'], '2026-09-01 12:00:00');
        $this->createAtTime(PrismExerciseCap::class, ['gym_id' => 'the-cove', 'exercise_id' => 'old-cap', 'cap_count' => 1, 'cap_period' => 'week', 'device_id' => 'dev-1'], '2026-09-01 10:00:00');
        $this->createAtTime(PrismExerciseCap::class, ['gym_id' => 'the-cove', 'exercise_id' => 'new-cap', 'cap_count' => 1, 'cap_period' => 'week', 'device_id' => 'dev-1'], '2026-09-01 12:00:00');

        $since = urlencode('2026-09-01T11:00:00+00:00');
        $response = $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->getJson("/api/sync/prism/the-cove/changes?since={$since}");

        $response->assertStatus(200);
        $this->assertSame(['new-family'], array_column($response->json('exercise_families'), 'family_id'));
        $this->assertSame(['new-member'], array_column($response->json('exercise_family_members'), 'exercise_id'));
        $this->assertSame(['new-cap'], array_column($response->json('exercise_caps'), 'exercise_id'));
    }

    #[DataProvider('exerciseLimitRows')]
    public function test_exercise_limit_tombstones_are_delivered_in_delta(string $modelClass, array $attributes, string $feedKey): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $tombstone = Carbon::parse('2026-09-01 12:00:00', config('app.timezone'));
        $this->createAtTime($modelClass, array_merge($attributes, ['deleted_at' => $tombstone]), '2026-09-01 12:00:00');

        $since = urlencode('2026-09-01T11:00:00+00:00');
        $response = $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->getJson("/api/sync/prism/the-cove/changes?since={$since}");

        $response->assertStatus(200);
        $rows = $response->json($feedKey);
        $this->assertCount(1, $rows);
        $this->assertNotNull($rows[0]['deleted_at']);
    }

    #[DataProvider('exerciseLimitRows')]
    public function test_cursor_advances_on_exercise_limit_update(string $modelClass, array $attributes, string $feedKey): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $tz = config('app.timezone');
        $t15 = Carbon::parse('2026-09-01 15:00:00', $tz);
        $this->createAtTime($modelClass, $attributes, '2026-09-01 15:00:00');

        $response = $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->getJson('/api/sync/prism/the-cove/changes');

        $response->assertStatus(200);
        $this->assertEquals($t15->toIso8601String(), $response->json('cursor'));
    }

    #[DataProvider('exerciseLimitRows')]
    public function test_cursor_advances_on_exercise_limit_removal(string $modelClass, array $attributes, string $feedKey): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $tz = config('app.timezone');
        $t15 = Carbon::parse('2026-09-01 15:00:00', $tz);
        $this->createAtTime($modelClass, array_merge($attributes, ['deleted_at' => $t15]), '2026-09-01 10:00:00');

        $response = $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->getJson('/api/sync/prism/the-cove/changes');

        $response->assertStatus(200);
        $this->assertEquals($t15->toIso8601String(), $response->json('cursor'));
    }

    public function test_other_gyms_exercise_limit_rows_never_appear(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        Gym::create(['id' => 'other-gym', 'name' => 'Other Gym']);

        $this->createAtTime(PrismExerciseFamily::class, ['gym_id' => 'other-gym', 'family_id' => 'other-family', 'name' => 'Other', 'device_id' => 'dev-1'], '2026-09-01 15:00:00');
        $this->createAtTime(PrismExerciseFamilyMember::class, ['gym_id' => 'other-gym', 'exercise_id' => 'other-member', 'family_id' => 'f', 'is_member' => true, 'device_id' => 'dev-1'], '2026-09-01 15:00:00');
        $this->createAtTime(PrismExerciseCap::class, ['gym_id' => 'other-gym', 'exercise_id' => 'other-cap', 'cap_count' => 1, 'cap_period' => 'week', 'device_id' => 'dev-1'], '2026-09-01 15:00:00');
        $this->createAtTime(PrismExerciseFamily::class, ['gym_id' => 'the-cove', 'family_id' => 'cove-family', 'name' => 'Cove', 'device_id' => 'dev-1'], '2026-09-01 10:00:00');
        $this->createAtTime(PrismExerciseFamilyMember::class, ['gym_id' => 'the-cove', 'exercise_id' => 'cove-member', 'family_id' => 'f', 'is_member' => true, 'device_id' => 'dev-1'], '2026-09-01 10:00:00');
        $this->createAtTime(PrismExerciseCap::class, ['gym_id' => 'the-cove', 'exercise_id' => 'cove-cap', 'cap_count' => 1, 'cap_period' => 'week', 'device_id' => 'dev-1'], '2026-09-01 10:00:00');

        $response = $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->getJson('/api/sync/prism/the-cove/changes');

        $response->assertStatus(200);
        $this->assertSame(['cove-family'], array_column($response->json('exercise_families'), 'family_id'));
        $this->assertSame(['cove-member'], array_column($response->json('exercise_family_members'), 'exercise_id'));
        $this->assertSame(['cove-cap'], array_column($response->json('exercise_caps'), 'exercise_id'));
        $this->assertEquals(Carbon::parse('2026-09-01 10:00:00', config('app.timezone'))->toIso8601String(), $response->json('cursor'));
    }
}
