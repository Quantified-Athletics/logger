<?php

namespace Tests\Feature\ExerciseSchema;

use App\Models\Exercise;
use App\Models\LiftLog;
use App\Models\LiftSet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Every writer stores a log_type and nothing writes exercise_type (#33 Step 3): the web UI form, the
 * sync endpoint (old clients that send only `exercise_type` included), the model itself, and the two
 * migrations that make the old column nullable and fill the exercises that have no log type.
 */
class LogTypeWritersTest extends TestCase
{
    use RefreshDatabase;

    private const NULLABLE_MIGRATION = 'database/migrations/2026_11_27_230000_make_exercise_type_nullable_on_exercises_table.php';

    private const FILL_MIGRATION = 'database/migrations/2026_11_27_230100_fill_empty_log_types_on_exercises_table.php';

    private User $user;

    /** @var array<string, string> */
    private array $syncHeaders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $token = $this->user->createToken('device-a')->plainTextToken;
        $this->syncHeaders = ['Authorization' => 'Bearer '.$token, 'X-Device-Id' => 'device-a'];
    }

    #[Test]
    public function an_old_sync_client_that_sends_only_exercise_type_stores_the_log_type_the_reverse_table_names(): void
    {
        $this->withHeaders($this->syncHeaders)->postJson('/api/sync/exercises', [
            'title' => 'Band Pull Apart',
            'canonical_name' => 'band_pull_apart',
            'exercise_type' => 'banded_resistance',
        ])->assertStatus(201);

        $exercise = Exercise::where('canonical_name', 'band_pull_apart')->firstOrFail();
        $this->assertSame('banded', $exercise->log_type);
        $this->assertNull($this->storedExerciseType($exercise));
    }

    #[Test]
    public function a_sync_client_that_sends_both_stores_its_log_type(): void
    {
        $this->withHeaders($this->syncHeaders)->postJson('/api/sync/exercises', [
            'title' => 'Goblet Squat',
            'canonical_name' => 'goblet_squat',
            'exercise_type' => 'regular',
            'log_type' => 'kettlebell',
        ])->assertStatus(201);

        $exercise = Exercise::where('canonical_name', 'goblet_squat')->firstOrFail();
        $this->assertSame('kettlebell', $exercise->log_type);
        $this->assertNull($this->storedExerciseType($exercise));
    }

    #[Test]
    public function a_sync_client_that_sends_neither_stores_a_regular_log_type(): void
    {
        $this->withHeaders($this->syncHeaders)->postJson('/api/sync/exercises', [
            'title' => 'Mystery Lift',
            'canonical_name' => 'mystery_lift',
        ])->assertStatus(201);

        $exercise = Exercise::where('canonical_name', 'mystery_lift')->firstOrFail();
        $this->assertSame('barbell', $exercise->log_type);
        $this->assertSame('regular', $exercise->strategyTypeName());
    }

    #[Test]
    public function an_old_sync_clients_update_without_log_type_keeps_a_log_type(): void
    {
        $exercise = Exercise::factory()->create([
            'user_id' => $this->user->id,
            'canonical_name' => 'plank',
            'log_type' => 'static-hold',
        ]);

        $this->withHeaders($this->syncHeaders)->postJson('/api/sync/exercises', [
            'title' => 'Plank',
            'canonical_name' => 'plank',
            'exercise_type' => 'static_hold',
        ])->assertOk();

        $this->assertSame('static-hold', $exercise->fresh()->log_type);
    }

    #[Test]
    public function the_sync_update_stores_the_clients_log_type(): void
    {
        $exercise = Exercise::factory()->create([
            'user_id' => $this->user->id,
            'canonical_name' => 'row',
            'log_type' => 'barbell',
        ]);

        $this->withHeaders($this->syncHeaders)->postJson('/api/sync/exercises', [
            'title' => 'Row',
            'canonical_name' => 'row',
            'exercise_type' => 'regular',
            'log_type' => 'dual-dumbbell',
        ])->assertOk();

        $this->assertSame('dual-dumbbell', $exercise->fresh()->log_type);
        $this->assertNull($this->storedExerciseType($exercise));
    }

    #[Test]
    public function the_ui_refuses_an_exercise_without_a_log_type(): void
    {
        $this->actingAs($this->user)->post(route('exercises.store'), [
            'title' => 'No Type Lift',
        ])->assertSessionHasErrors('log_type');

        $this->assertDatabaseMissing('exercises', ['title' => 'No Type Lift']);
    }

    #[Test]
    public function the_ui_refuses_a_log_type_that_is_not_in_the_list(): void
    {
        $this->actingAs($this->user)->post(route('exercises.store'), [
            'title' => 'Odd Lift',
            'log_type' => 'regular',
        ])->assertSessionHasErrors('log_type');

        $this->assertDatabaseMissing('exercises', ['title' => 'Odd Lift']);
    }

    #[Test]
    public function the_ui_stores_the_chosen_log_type_and_no_exercise_type(): void
    {
        $this->actingAs($this->user)->post(route('exercises.store'), [
            'title' => 'Banded Dips',
            'log_type' => 'banded',
        ])->assertRedirect(route('exercises.index'));

        $exercise = Exercise::where('title', 'Banded Dips')->firstOrFail();
        $this->assertSame('banded', $exercise->log_type);
        $this->assertSame('banded_resistance', $exercise->strategyTypeName());
        $this->assertNull($this->storedExerciseType($exercise));
    }

    #[Test]
    public function the_ui_edit_stores_the_chosen_log_type(): void
    {
        $exercise = Exercise::factory()->create(['user_id' => $this->user->id, 'log_type' => 'barbell']);

        $this->actingAs($this->user)->put(route('exercises.update', $exercise), [
            'title' => $exercise->title,
            'log_type' => 'cardio-calories',
        ])->assertRedirect(route('exercises.edit', $exercise));

        $this->assertSame('cardio-calories', $exercise->fresh()->log_type);
        $this->assertNull($this->storedExerciseType($exercise));
    }

    #[Test]
    public function the_ui_edit_refuses_to_remove_the_log_type(): void
    {
        $exercise = Exercise::factory()->create(['user_id' => $this->user->id, 'log_type' => 'bodyweight']);

        $this->actingAs($this->user)->put(route('exercises.update', $exercise), [
            'title' => $exercise->title,
            'log_type' => '',
        ])->assertSessionHasErrors('log_type');

        $this->assertSame('bodyweight', $exercise->fresh()->log_type);
    }

    #[Test]
    public function the_form_offers_log_types_where_it_offered_exercise_types(): void
    {
        $response = $this->actingAs($this->user)->get(route('exercises.create'));

        $response->assertOk();
        $response->assertSee('name="log_type"', false);
        $response->assertDontSee('name="exercise_type"', false);
        $response->assertSee('value="weighted-carry-2-kb"', false);
    }

    #[Test]
    public function an_exercise_saved_without_a_log_type_still_gets_one(): void
    {
        $exercise = Exercise::create(['title' => 'Bare Exercise']);

        $this->assertSame('barbell', $exercise->fresh()->log_type);
        $this->assertNull($this->storedExerciseType($exercise));
    }

    #[Test]
    public function the_model_no_longer_accepts_exercise_type(): void
    {
        $exercise = new Exercise;

        $this->assertNotContains('exercise_type', $exercise->getFillable());
        $this->assertArrayNotHasKey('exercise_type', $exercise->getCasts());
        $this->assertNotContains('exercise_type', $exercise->getActivitylogOptions()->logAttributes);
    }

    #[Test]
    public function the_exercise_type_column_accepts_no_value(): void
    {
        DB::table('exercises')->insert([
            'title' => 'Raw Insert',
            'canonical_name' => 'raw_insert',
            'log_type' => 'barbell',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('exercises', ['canonical_name' => 'raw_insert', 'exercise_type' => null]);
    }

    #[Test]
    public function the_nullable_migration_rolls_back_by_filling_the_strategy_from_the_log_type(): void
    {
        $id = DB::table('exercises')->insertGetId([
            'title' => 'Rolled Back Carry',
            'canonical_name' => 'rolled_back_carry',
            'log_type' => 'weighted-carry-2-kb',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $noLogTypeId = DB::table('exercises')->insertGetId([
            'title' => 'Rolled Back Unknown',
            'canonical_name' => 'rolled_back_unknown',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require base_path(self::NULLABLE_MIGRATION);
        $migration->down();

        $this->assertSame('load_output', DB::table('exercises')->where('id', $id)->value('exercise_type'));
        $this->assertSame('regular', DB::table('exercises')->where('id', $noLogTypeId)->value('exercise_type'));

        $migration->up();
        $this->the_exercise_type_column_accepts_no_value();
    }

    #[Test]
    public function the_fill_migration_gives_every_exercise_a_log_type(): void
    {
        $caloriesCardio = $this->exerciseWithoutLogType('Assault Bike', 'cardio');
        $distanceCardio = $this->exerciseWithoutLogType('Row Erg', 'cardio');
        $unloggedCardio = $this->exerciseWithoutLogType('Ski Erg', 'cardio');
        $regular = $this->exerciseWithoutLogType('Old Press', 'regular', $this->user->id);
        $nullLogType = $this->exerciseWithoutLogType('Old Curl', 'regular', $this->user->id, null);
        $untouched = Exercise::factory()->create(['log_type' => 'kettlebell']);

        $this->logSet($caloriesCardio, ['calories' => 20]);
        $this->logSet($caloriesCardio, ['calories' => 25]);
        $this->logSet($distanceCardio, ['distance' => 500, 'distance_unit' => 'm']);
        $activitiesBefore = Activity::count();

        (require base_path(self::FILL_MIGRATION))->up();

        $this->assertSame('cardio-calories', $this->storedLogType($caloriesCardio));
        $this->assertSame('cardio-distance', $this->storedLogType($distanceCardio));
        $this->assertSame('cardio-distance', $this->storedLogType($unloggedCardio));
        $this->assertSame('barbell', $this->storedLogType($regular));
        $this->assertSame('barbell', $this->storedLogType($nullLogType));
        $this->assertSame('kettlebell', $untouched->fresh()->log_type);
        $this->assertSame(0, DB::table('exercises')->whereNull('log_type')->orWhere('log_type', '')->count());
        $this->assertSame($activitiesBefore, Activity::count());
    }

    private function exerciseWithoutLogType(string $title, string $exerciseType, ?int $userId = null, ?string $logType = ''): int
    {
        return DB::table('exercises')->insertGetId([
            'title' => $title,
            'canonical_name' => str($title)->snake()->toString(),
            'exercise_type' => $exerciseType,
            'log_type' => $logType,
            'user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function logSet(int $exerciseId, array $values): void
    {
        $liftLog = LiftLog::factory()->create(['exercise_id' => $exerciseId, 'user_id' => $this->user->id]);
        LiftSet::factory()->create(['lift_log_id' => $liftLog->id, 'weight' => 0, 'reps' => 0, ...$values]);
    }

    private function storedLogType(int $exerciseId): ?string
    {
        return DB::table('exercises')->where('id', $exerciseId)->value('log_type');
    }

    private function storedExerciseType(Exercise $exercise): ?string
    {
        return DB::table('exercises')->where('id', $exercise->id)->value('exercise_type');
    }
}
