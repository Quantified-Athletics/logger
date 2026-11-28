<?php

namespace Tests\Feature\ExerciseLibrary;

use App\Models\Exercise;
use App\Models\ExerciseAlias;
use App\Models\ExerciseMergeLog;
use App\Models\LiftLog;
use App\Models\PersonalRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * M7 step 2 (#42): `exercise-library:apply` applies an ops file to the standard exercises (or, with
 * `--check`, only proves it would), and `exercise-library:export` prints the standard exercises in
 * the shape the root's contracts read. Fixture ops only; the data file is S3's.
 */
class ExerciseLibraryCommandsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    private array $opsFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->opsFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    /**
     * @param  list<array<string, mixed>>  $ops
     */
    private function opsFile(array $ops): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ops').'.json';
        file_put_contents($path, json_encode(['ops' => $ops]));
        $this->opsFiles[] = $path;

        return $path;
    }

    private function standard(string $canonicalName, string $name, string $logType = 'barbell'): Exercise
    {
        return Exercise::factory()->create([
            'canonical_name' => $canonicalName,
            'name' => $name,
            'log_type' => $logType,
            'user_id' => null,
        ]);
    }

    private function standardAlias(Exercise $exercise, string $aliasName): ExerciseAlias
    {
        return ExerciseAlias::create(['user_id' => null, 'exercise_id' => $exercise->id, 'alias_name' => $aliasName]);
    }

    /**
     * @return list<string>
     */
    private function standardAliasNames(Exercise $exercise): array
    {
        return ExerciseAlias::query()
            ->where('exercise_id', $exercise->id)
            ->whereNull('user_id')
            ->orderBy('alias_name')
            ->pluck('alias_name')
            ->all();
    }

    /**
     * A snapshot of everything an apply could write, to prove a run wrote nothing.
     *
     * @return array<string, mixed>
     */
    private function databaseSnapshot(): array
    {
        return [
            'exercises' => Exercise::withTrashed()->orderBy('id')->get()->map->getAttributes()->all(),
            'aliases' => ExerciseAlias::withTrashed()->orderBy('id')->get()->map->getAttributes()->all(),
            'lift_logs' => LiftLog::withTrashed()->orderBy('id')->get()->map->getAttributes()->all(),
            'personal_records' => PersonalRecord::withTrashed()->orderBy('id')->get()->map->getAttributes()->all(),
            'merge_logs' => ExerciseMergeLog::query()->count(),
            'activity' => Activity::query()->count(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function export(): array
    {
        $this->assertSame(0, Artisan::call('exercise-library:export', ['--no-interaction' => true]));

        $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    #[Test]
    public function a_valid_update_applies_its_fields_under_their_columns(): void
    {
        $exercise = $this->standard('back_squat', 'Back Squat');

        $this->artisan('exercise-library:apply', ['--ops' => $this->opsFile([[
            'op' => 'update',
            'id' => 'back_squat',
            'set' => [
                'name' => 'Barbell Back Squat',
                'primaryMuscles' => ['quads', 'glutes'],
                'regionsLoaded' => ['lower-back' => 'heavy'],
                'maxPerWeek' => 2,
                'generatorEligible' => true,
                'motion' => 'squat',
                'logType' => 'barbell',
            ],
            'reason' => 'plan §5.2: the canonical squat',
        ]])])->assertExitCode(0);

        $exercise->refresh();
        $this->assertSame('Barbell Back Squat', $exercise->name);
        $this->assertSame(['quads', 'glutes'], $exercise->primary_muscles);
        $this->assertSame(['lower-back' => 'heavy'], $exercise->regions_loaded);
        $this->assertSame(2, $exercise->max_per_week);
        $this->assertTrue($exercise->generator_eligible);
        $this->assertSame('squat', $exercise->motion);
    }

    #[Test]
    public function the_reason_goes_into_the_activity_description_with_the_old_values(): void
    {
        $exercise = $this->standard('back_squat', 'Back Squat');
        $exercise->update(['motion' => 'hinge']);

        $this->artisan('exercise-library:apply', ['--ops' => $this->opsFile([[
            'op' => 'update', 'id' => 'back_squat', 'set' => ['motion' => 'squat'], 'reason' => 'squats squat',
        ]])])->assertExitCode(0);

        $activity = Activity::query()->where('subject_type', Exercise::class)->where('subject_id', $exercise->id)
            ->latest('id')->first();
        $this->assertSame('squats squat', $activity->description);
        $this->assertSame('hinge', $activity->properties['old']['motion']);
        $this->assertSame('squat', $activity->properties['attributes']['motion']);
    }

    #[Test]
    public function null_clears_a_column_and_an_empty_list_counts_as_absent(): void
    {
        $exercise = $this->standard('back_squat', 'Back Squat');
        $exercise->update(['motion' => 'squat', 'equipment' => ['barbell'], 'regions_loaded' => ['neck' => 'light']]);

        $this->artisan('exercise-library:apply', ['--ops' => $this->opsFile([[
            'op' => 'update', 'id' => 'back_squat',
            'set' => ['motion' => null, 'equipment' => [], 'regionsLoaded' => (object) []],
            'reason' => 'cleared',
        ]])])->assertExitCode(0);

        $exercise->refresh();
        $this->assertNull($exercise->motion);
        $this->assertNull($exercise->equipment);
        $this->assertNull($exercise->regions_loaded);
    }

    #[Test]
    public function aliases_replace_the_standard_aliases_and_keep_the_user_aliases(): void
    {
        $exercise = $this->standard('back_squat', 'Back Squat');
        $this->standardAlias($exercise, 'Squat');
        $this->standardAlias($exercise, 'BS');
        $user = User::factory()->create();
        ExerciseAlias::create(['user_id' => $user->id, 'exercise_id' => $exercise->id, 'alias_name' => 'My Squat']);

        $this->artisan('exercise-library:apply', ['--ops' => $this->opsFile([[
            'op' => 'update', 'id' => 'back_squat', 'set' => ['aliases' => ['Squat', 'High Bar Squat']], 'reason' => 'aliases',
        ]])])->assertExitCode(0);

        $this->assertSame(['High Bar Squat', 'Squat'], $this->standardAliasNames($exercise));
        $this->assertTrue(ExerciseAlias::query()->where('user_id', $user->id)->where('alias_name', 'My Squat')->exists());
    }

    #[Test]
    public function an_add_creates_a_standard_exercise(): void
    {
        $this->artisan('exercise-library:apply', ['--ops' => $this->opsFile([[
            'op' => 'add', 'id' => 'sled_push', 'set' => ['name' => 'Sled Push', 'logType' => 'bodyweight', 'kinds' => ['conditioning']],
            'reason' => 'new in the library',
        ]])])->assertExitCode(0);

        $exercise = Exercise::query()->global()->where('canonical_name', 'sled_push')->sole();
        $this->assertSame('Sled Push', $exercise->name);
        $this->assertSame('bodyweight', $exercise->log_type);
        $this->assertSame(['conditioning'], $exercise->kinds);
    }

    #[Test]
    public function check_writes_nothing_and_exits_zero_when_every_op_would_apply(): void
    {
        $source = $this->standard('air_squats', 'Air Squats', 'bodyweight');
        $this->standard('air_squat', 'Air Squat', 'bodyweight');
        LiftLog::factory()->create(['exercise_id' => $source->id]);
        $before = $this->databaseSnapshot();

        $this->artisan('exercise-library:apply', ['--ops' => $this->opsFile([
            ['op' => 'add', 'id' => 'sled_push', 'set' => ['name' => 'Sled Push', 'logType' => 'bodyweight'], 'reason' => 'new'],
            ['op' => 'merge', 'id' => 'air_squats', 'into' => 'air_squat', 'reason' => 'same lift'],
            ['op' => 'update', 'id' => 'air_squat', 'set' => ['motion' => 'squat', 'aliases' => ['Squat']], 'reason' => 'motion'],
        ]), '--check' => true])->assertExitCode(0);

        $this->assertEquals($before, $this->databaseSnapshot());
    }

    #[Test]
    public function a_second_run_of_the_same_file_changes_nothing(): void
    {
        $source = $this->standard('air_squats', 'Air Squats', 'bodyweight');
        $target = $this->standard('air_squat', 'Air Squat', 'bodyweight');
        $this->standardAlias($source, 'Squats');
        LiftLog::factory()->create(['exercise_id' => $source->id]);
        $file = $this->opsFile([
            ['op' => 'add', 'id' => 'sled_push', 'set' => ['name' => 'Sled Push', 'logType' => 'bodyweight'], 'reason' => 'new'],
            ['op' => 'merge', 'id' => 'air_squats', 'into' => 'air_squat', 'reason' => 'same lift'],
            ['op' => 'update', 'id' => 'air_squat', 'set' => [
                'formerIds' => ['air_squats'], 'aliases' => ['Squats', 'Air Squats'], 'regionsLoaded' => ['knees' => 'light'],
                'createdAt' => null,
            ], 'reason' => 'canonical'],
        ]);

        $this->artisan('exercise-library:apply', ['--ops' => $file])->assertExitCode(0);
        $afterFirst = $this->databaseSnapshot();

        $this->artisan('exercise-library:apply', ['--ops' => $file])->assertExitCode(0);
        $this->artisan('exercise-library:apply', ['--ops' => $file, '--check' => true])->assertExitCode(0);

        $this->assertEquals($afterFirst, $this->databaseSnapshot());
        $this->assertSame(['Air Squats', 'Squats'], $this->standardAliasNames($target));
    }

    #[Test]
    public function a_merge_moves_the_logs_prs_and_aliases_and_leaves_none_behind(): void
    {
        $source = $this->standard('air_squats', 'Air Squats', 'bodyweight');
        $target = $this->standard('air_squat', 'Air Squat', 'bodyweight');
        $user = User::factory()->create();
        $logs = LiftLog::factory()->count(2)->create(['exercise_id' => $source->id, 'user_id' => $user->id]);
        $trashedLog = LiftLog::factory()->create(['exercise_id' => $source->id, 'user_id' => $user->id]);
        $trashedLog->delete();
        PersonalRecord::factory()->create(['exercise_id' => $source->id, 'user_id' => $user->id, 'lift_log_id' => $logs[0]->id]);
        ExerciseAlias::create(['user_id' => $user->id, 'exercise_id' => $source->id, 'alias_name' => 'My Squats']);
        $this->standardAlias($source, 'Bodyweight Squats');

        $this->artisan('exercise-library:apply', ['--ops' => $this->opsFile([
            ['op' => 'merge', 'id' => 'air_squats', 'into' => 'air_squat', 'reason' => "plan §5.2: Logger's air_squats is air_squat"],
        ])])->assertExitCode(0);

        $this->assertSame(0, LiftLog::withTrashed()->where('exercise_id', $source->id)->count());
        $this->assertSame(3, LiftLog::withTrashed()->where('exercise_id', $target->id)->count());
        $this->assertSame(0, PersonalRecord::withTrashed()->where('exercise_id', $source->id)->count());
        $this->assertSame(1, PersonalRecord::query()->where('exercise_id', $target->id)->count());
        $this->assertSame(0, ExerciseAlias::query()->where('exercise_id', $source->id)->count());
        $this->assertTrue(ExerciseAlias::query()->where('exercise_id', $target->id)->where('user_id', $user->id)->where('alias_name', 'My Squats')->exists());
        $this->assertSame(['Bodyweight Squats'], $this->standardAliasNames($target));

        $this->assertSoftDeleted($source);
        $this->assertSame(['air_squats'], $target->refresh()->former_ids);

        $mergeLog = ExerciseMergeLog::query()->sole();
        $this->assertSame($source->id, $mergeLog->source_exercise_id);
        $this->assertSame($target->id, $mergeLog->target_exercise_id);
        $this->assertSame(3, $mergeLog->lift_log_count);
        $this->assertSame("plan §5.2: Logger's air_squats is air_squat", $mergeLog->snapshot['reason']);
    }

    #[Test]
    public function a_merge_whose_target_gets_aliases_in_the_same_file_retires_the_merged_rows_standard_aliases(): void
    {
        $source = $this->standard('air_squats', 'Air Squats', 'bodyweight');
        $target = $this->standard('air_squat', 'Air Squat', 'bodyweight');
        $this->standardAlias($source, 'Bodyweight Squats');

        $this->artisan('exercise-library:apply', ['--ops' => $this->opsFile([
            ['op' => 'merge', 'id' => 'air_squats', 'into' => 'air_squat', 'reason' => 'same lift'],
            ['op' => 'update', 'id' => 'air_squat', 'set' => ['aliases' => ['Air Squats']], 'reason' => 'aliases'],
        ])])->assertExitCode(0);

        $this->assertSame(['Air Squats'], $this->standardAliasNames($target));
        $this->assertSame(0, ExerciseAlias::query()->where('exercise_id', $source->id)->count());
    }

    #[Test]
    public function a_merge_runs_after_the_add_of_its_target_even_when_it_sorts_first(): void
    {
        $source = $this->standard('air_squats', 'Air Squats', 'bodyweight');
        LiftLog::factory()->create(['exercise_id' => $source->id]);

        $this->artisan('exercise-library:apply', ['--ops' => $this->opsFile([
            ['op' => 'merge', 'id' => 'air_squats', 'into' => 'b_air_squat', 'reason' => 'same lift'],
            ['op' => 'add', 'id' => 'b_air_squat', 'set' => ['name' => 'Air Squat', 'logType' => 'bodyweight'], 'reason' => 'new'],
        ])])->assertExitCode(0);

        $target = Exercise::query()->global()->where('canonical_name', 'b_air_squat')->sole();
        $this->assertSame(1, LiftLog::query()->where('exercise_id', $target->id)->count());
    }

    #[Test]
    public function an_op_without_a_reason_is_refused_and_nothing_is_written(): void
    {
        $this->standard('back_squat', 'Back Squat');
        $before = $this->databaseSnapshot();

        $this->artisan('exercise-library:apply', ['--ops' => $this->opsFile([
            ['op' => 'update', 'id' => 'back_squat', 'set' => ['motion' => 'squat'], 'reason' => 'fine'],
            ['op' => 'update', 'id' => 'back_squat', 'set' => ['category' => 'legs']],
        ])])->expectsOutputToContain('reason')->assertFailed();

        $this->assertEquals($before, $this->databaseSnapshot());
    }

    #[Test]
    public function an_op_with_a_blank_reason_is_refused_under_check(): void
    {
        $this->standard('back_squat', 'Back Squat');

        $this->artisan('exercise-library:apply', ['--ops' => $this->opsFile([
            ['op' => 'update', 'id' => 'back_squat', 'set' => ['motion' => 'squat'], 'reason' => '  '],
        ]), '--check' => true])->assertFailed();
    }

    #[Test]
    public function a_log_type_outside_the_registry_is_refused(): void
    {
        $exercise = $this->standard('back_squat', 'Back Squat');

        $this->artisan('exercise-library:apply', ['--ops' => $this->opsFile([
            ['op' => 'update', 'id' => 'back_squat', 'set' => ['logType' => 'regular'], 'reason' => 'old strategy name'],
        ])])->expectsOutputToContain('regular')->assertFailed();

        $this->assertSame('barbell', $exercise->refresh()->log_type);
    }

    #[Test]
    public function clearing_a_standard_exercises_log_type_is_refused(): void
    {
        $this->standard('back_squat', 'Back Squat');

        $this->artisan('exercise-library:apply', ['--ops' => $this->opsFile([
            ['op' => 'update', 'id' => 'back_squat', 'set' => ['logType' => null], 'reason' => 'cleared'],
        ]), '--check' => true])->assertFailed();
    }

    #[Test]
    public function an_add_with_a_log_type_outside_the_registry_is_refused(): void
    {
        $this->artisan('exercise-library:apply', ['--ops' => $this->opsFile([
            ['op' => 'add', 'id' => 'sled_push', 'set' => ['name' => 'Sled Push', 'logType' => 'sled'], 'reason' => 'new'],
        ])])->assertFailed();

        $this->assertFalse(Exercise::query()->where('canonical_name', 'sled_push')->exists());
    }

    #[Test]
    public function an_update_naming_no_standard_row_is_refused_and_no_row_is_created(): void
    {
        $user = User::factory()->create();
        Exercise::factory()->create(['canonical_name' => 'my_squat', 'user_id' => $user->id]);
        $trashed = $this->standard('old_squat', 'Old Squat');
        $trashed->delete();

        $this->artisan('exercise-library:apply', ['--ops' => $this->opsFile([
            ['op' => 'update', 'id' => 'ghost_squat', 'set' => ['motion' => 'squat'], 'reason' => 'r'],
            ['op' => 'update', 'id' => 'my_squat', 'set' => ['motion' => 'squat'], 'reason' => 'r'],
            ['op' => 'update', 'id' => 'old_squat', 'set' => ['motion' => 'squat'], 'reason' => 'r'],
        ])])
            ->expectsOutputToContain('ghost_squat')
            ->expectsOutputToContain('my_squat')
            ->expectsOutputToContain('old_squat')
            ->assertFailed();

        $this->assertSame(0, Exercise::query()->where('canonical_name', 'ghost_squat')->count());
        $this->assertNull(Exercise::query()->where('canonical_name', 'my_squat')->sole()->motion);
    }

    #[Test]
    public function a_merge_naming_no_standard_row_is_refused(): void
    {
        $this->standard('air_squat', 'Air Squat', 'bodyweight');
        $this->standard('air_squats', 'Air Squats', 'bodyweight');

        $this->artisan('exercise-library:apply', ['--ops' => $this->opsFile([
            ['op' => 'merge', 'id' => 'ghost_squats', 'into' => 'air_squat', 'reason' => 'r'],
        ])])->expectsOutputToContain('ghost_squats')->assertFailed();

        $this->artisan('exercise-library:apply', ['--ops' => $this->opsFile([
            ['op' => 'merge', 'id' => 'air_squats', 'into' => 'ghost_squat', 'reason' => 'r'],
        ])])->expectsOutputToContain('ghost_squat')->assertFailed();

        $this->assertSame(0, ExerciseMergeLog::query()->count());
    }

    #[Test]
    public function an_unknown_field_or_op_is_refused(): void
    {
        $this->standard('back_squat', 'Back Squat');

        $this->artisan('exercise-library:apply', ['--ops' => $this->opsFile([
            ['op' => 'update', 'id' => 'back_squat', 'set' => ['colour' => 'red'], 'reason' => 'r'],
        ]), '--check' => true])->expectsOutputToContain('colour')->assertFailed();

        $this->artisan('exercise-library:apply', ['--ops' => $this->opsFile([
            ['op' => 'rename', 'id' => 'back_squat', 'reason' => 'r'],
        ]), '--check' => true])->assertFailed();
    }

    #[Test]
    public function a_missing_or_unreadable_ops_file_is_refused(): void
    {
        $this->artisan('exercise-library:apply', ['--ops' => '/nonexistent/ops.json', '--check' => true])->assertFailed();

        $path = tempnam(sys_get_temp_dir(), 'ops');
        file_put_contents($path, '{not json');
        $this->opsFiles[] = $path;
        $this->artisan('exercise-library:apply', ['--ops' => $path, '--check' => true])->assertFailed();
    }

    #[Test]
    public function export_prints_the_standard_exercises_in_the_contract_shape(): void
    {
        $squat = $this->standard('back_squat', 'Back Squat');
        $squat->update(['primary_muscles' => ['quads'], 'regions_loaded' => ['lower-back' => 'heavy'], 'max_per_week' => 2]);
        $this->standardAlias($squat, 'Squat');
        $user = User::factory()->create();
        ExerciseAlias::create(['user_id' => $user->id, 'exercise_id' => $squat->id, 'alias_name' => 'My Squat']);
        $this->standard('air_squat', 'Air Squat', 'bodyweight');
        $this->standard('gone', 'Gone')->delete();
        Exercise::factory()->create(['canonical_name' => 'mine', 'user_id' => $user->id]);

        $rows = $this->export();

        $this->assertSame(['air_squat', 'back_squat'], array_column($rows, 'canonical_name'));

        $expectedKeys = [
            'canonical_name', 'name', 'aliases', 'former_ids', 'log_type', 'equipment', 'primary_muscles',
            'core_muscles', 'regions_loaded', 'primary_movements', 'core_movements', 'motion', 'modalities',
            'kinds', 'laterality', 'families', 'category', 'max_per_week', 'repeat_cooldown', 'suggestion_count',
            'suggestion_period', 'video', 'generator_eligible', 'historic_reps', 'modifier', 'created_at',
            'members', 'bucket', 'tags',
        ];
        $this->assertEqualsCanonicalizing($expectedKeys, array_keys($rows[1]));

        $this->assertSame('Back Squat', $rows[1]['name']);
        $this->assertSame('barbell', $rows[1]['log_type']);
        $this->assertSame(['Squat'], $rows[1]['aliases']);
        $this->assertSame(['quads'], $rows[1]['primary_muscles']);
        $this->assertSame(['lower-back' => 'heavy'], $rows[1]['regions_loaded']);
        $this->assertSame(2, $rows[1]['max_per_week']);
        $this->assertNull($rows[1]['motion']);
        $this->assertNull($rows[1]['equipment']);
        $this->assertSame([], $rows[0]['aliases']);
    }

    #[Test]
    public function export_prints_nothing_but_the_json_array(): void
    {
        $this->standard('back_squat', 'Back Squat');

        Artisan::call('exercise-library:export', ['--no-interaction' => true]);
        $output = trim(Artisan::output());

        $this->assertStringStartsWith('[', $output);
        $this->assertStringEndsWith(']', $output);
        $this->assertIsList(json_decode($output, true, flags: JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function export_prints_an_empty_array_without_standard_exercises(): void
    {
        $this->assertSame([], $this->export());
    }

    #[Test]
    public function an_applied_file_reads_back_through_export(): void
    {
        $this->standard('back_squat', 'Back Squat');

        $this->artisan('exercise-library:apply', ['--ops' => $this->opsFile([[
            'op' => 'update', 'id' => 'back_squat',
            'set' => ['aliases' => ['Squat'], 'historicReps' => ['5', '3-5'], 'video' => 'https://example.test/v'],
            'reason' => 'r',
        ]])])->assertExitCode(0);

        $row = $this->export()[0];
        $this->assertSame(['Squat'], $row['aliases']);
        $this->assertSame(['5', '3-5'], $row['historic_reps']);
        $this->assertSame('https://example.test/v', $row['video']);
    }
}
