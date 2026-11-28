<?php

namespace Tests\Feature\ExerciseLibrary;

use App\ExerciseLibrary\ExerciseLibraryOpsRefusedException;
use App\Models\Exercise;
use App\Models\ExerciseAlias;
use App\Models\ExerciseMergeLog;
use App\Models\LiftLog;
use App\Models\RehydrationSignal;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * M7 step 3 (#42): the data migration applies `database/data/exercise-library/2026-12-canonical.json`
 * to the standard exercises through the same service as `exercise-library:apply`. The fixture seeds a
 * standard row for every id the file names, a few the file doesn't name, and a log on every merged row.
 */
class AlignStandardExercisesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_11_28_130000_align_standard_exercises_with_exercise_library.php';

    private const UNTOUCHED = ['zz_fixture_untouched_one', 'zz_fixture_untouched_two'];

    /**
     * @var list<array<string, mixed>>
     */
    private array $ops;

    protected function setUp(): void
    {
        parent::setUp();

        $document = json_decode(
            (string) file_get_contents(database_path('data/exercise-library/2026-12-canonical.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->ops = $document['ops'];
    }

    private function migration(): Migration
    {
        return require database_path(self::MIGRATION);
    }

    /**
     * @return list<string>
     */
    private function idsOf(string $kind): array
    {
        return array_values(array_map(
            fn (array $op): string => $op['id'],
            array_filter($this->ops, fn (array $op): bool => $op['op'] === $kind),
        ));
    }

    /**
     * A copy of the standard rows: one per id the file updates or merges, plus rows it never names,
     * a user's own exercise, and a log (one soft-deleted) and a user alias on every merged row.
     *
     * @return array{user: User, logs: int}
     */
    private function seedStandardRows(): array
    {
        foreach ([...$this->idsOf('update'), ...$this->idsOf('merge'), ...self::UNTOUCHED] as $canonicalName) {
            Exercise::factory()->create([
                'canonical_name' => $canonicalName,
                'name' => $canonicalName,
                'log_type' => 'barbell',
                'user_id' => null,
            ]);
        }

        $user = User::factory()->create();
        Exercise::factory()->create(['user_id' => $user->id, 'canonical_name' => 'my_own_lift', 'name' => 'My Own Lift']);

        foreach ($this->idsOf('merge') as $index => $canonicalName) {
            $source = Exercise::query()->where('canonical_name', $canonicalName)->firstOrFail();
            LiftLog::factory()->create(['exercise_id' => $source->id, 'user_id' => $user->id]);
            if ($index === 0) {
                LiftLog::factory()->create(['exercise_id' => $source->id, 'user_id' => $user->id])->delete();
            }
            ExerciseAlias::create(['user_id' => $user->id, 'exercise_id' => $source->id, 'alias_name' => "my {$canonicalName}"]);
        }

        return ['user' => $user, 'logs' => LiftLog::withTrashed()->count()];
    }

    /**
     * @return array<string, mixed>
     */
    private function databaseSnapshot(): array
    {
        return [
            'exercises' => Exercise::withTrashed()->orderBy('id')->get()->map->getAttributes()->all(),
            'aliases' => ExerciseAlias::withTrashed()->orderBy('id')->get()->map->getAttributes()->all(),
            'lift_logs' => LiftLog::withTrashed()->orderBy('id')->get()->map->getAttributes()->all(),
            'merge_logs' => ExerciseMergeLog::query()->count(),
            'activity' => Activity::query()->count(),
        ];
    }

    #[Test]
    public function the_migration_applies_the_data_file_to_the_standard_rows(): void
    {
        $this->seedStandardRows();

        $this->migration()->up();

        foreach ($this->ops as $op) {
            if ($op['op'] === 'merge') {
                $this->assertTrue(
                    Exercise::withTrashed()->whereNull('user_id')->where('canonical_name', $op['id'])->firstOrFail()->trashed(),
                    "{$op['id']} should be merged away",
                );
                $target = Exercise::query()->whereNull('user_id')->where('canonical_name', $op['into'])->firstOrFail();
                $this->assertContains($op['id'], $target->former_ids);

                continue;
            }

            $row = Exercise::query()->whereNull('user_id')->where('canonical_name', $op['id'])->firstOrFail();
            if (array_key_exists('name', $op['set'] ?? [])) {
                $this->assertSame($op['set']['name'], $row->name);
            }
            if (array_key_exists('logType', $op['set'] ?? [])) {
                $this->assertSame($op['set']['logType'], $row->log_type);
            }
        }

        $this->assertSame(count($this->idsOf('merge')), ExerciseMergeLog::query()->count());
    }

    #[Test]
    public function every_standard_row_afterwards_is_named_by_the_file_or_untouched(): void
    {
        $this->seedStandardRows();
        $untouchedBefore = Exercise::query()->whereIn('canonical_name', self::UNTOUCHED)->orderBy('id')->get()->map->getAttributes()->all();

        $this->migration()->up();

        $namedIds = array_merge($this->idsOf('update'), array_column($this->ops, 'into'));
        foreach (Exercise::query()->whereNull('user_id')->pluck('canonical_name') as $canonicalName) {
            $this->assertTrue(
                in_array($canonicalName, $namedIds, true) || in_array($canonicalName, self::UNTOUCHED, true),
                "{$canonicalName} is neither in the file's ops nor untouched",
            );
        }

        $this->assertSame(
            $untouchedBefore,
            Exercise::query()->whereIn('canonical_name', self::UNTOUCHED)->orderBy('id')->get()->map->getAttributes()->all(),
        );
        $this->assertDatabaseHas('exercises', ['canonical_name' => 'my_own_lift', 'name' => 'My Own Lift', 'deleted_at' => null]);
    }

    #[Test]
    public function the_migration_loses_no_log(): void
    {
        ['user' => $user, 'logs' => $logCount] = $this->seedStandardRows();

        $this->migration()->up();

        $this->assertSame($logCount, LiftLog::withTrashed()->count());
        foreach (LiftLog::withTrashed()->get() as $log) {
            $exercise = Exercise::withTrashed()->find($log->exercise_id);
            $this->assertNotNull($exercise);
            $this->assertFalse($exercise->trashed(), "log {$log->id} is left on merged row {$exercise->canonical_name}");
        }
        foreach ($this->idsOf('merge') as $canonicalName) {
            $source = Exercise::withTrashed()->where('canonical_name', $canonicalName)->firstOrFail();
            $this->assertSame(0, ExerciseAlias::query()->where('exercise_id', $source->id)->count());
        }
        $targets = array_values(array_unique(array_filter(array_column($this->ops, 'into'))));
        $this->assertSame(count($targets), ExerciseAlias::query()->where('user_id', $user->id)->count());
        foreach ($targets as $canonicalName) {
            $target = Exercise::query()->whereNull('user_id')->where('canonical_name', $canonicalName)->firstOrFail();
            $this->assertSame(1, ExerciseAlias::query()->where('user_id', $user->id)->where('exercise_id', $target->id)->count());
        }
    }

    #[Test]
    public function a_second_run_changes_nothing(): void
    {
        $this->seedStandardRows();
        $this->migration()->up();
        $afterFirstRun = $this->databaseSnapshot();

        $this->migration()->up();

        $this->assertSame($afterFirstRun, $this->databaseSnapshot());
    }

    #[Test]
    public function a_database_without_standard_exercises_is_left_alone(): void
    {
        $user = User::factory()->create();
        Exercise::factory()->create(['user_id' => $user->id, 'canonical_name' => 'my_own_lift']);
        $before = $this->databaseSnapshot();

        $this->migration()->up();

        $this->assertSame($before, $this->databaseSnapshot());
    }

    #[Test]
    public function standard_rows_the_file_cant_apply_to_stop_the_migration_and_nothing_is_written(): void
    {
        $this->seedStandardRows();
        Exercise::query()->where('canonical_name', $this->idsOf('update')[0])->firstOrFail()->forceDelete();
        $before = $this->databaseSnapshot();

        try {
            $this->migration()->up();
            $this->fail('The migration applied a file that names a row no standard exercise has.');
        } catch (ExerciseLibraryOpsRefusedException $refused) {
            $this->assertNotEmpty($refused->refusals);
        }

        $this->assertSame($before, $this->databaseSnapshot());
    }

    /**
     * Next to the fixture's user (logs on every merged row), a user whose only log on a merged row is
     * soft-deleted and a user with a log only on a row the file doesn't merge.
     *
     * @return array{user: User, softDeletedOnly: User, unaffected: User}
     */
    private function seedStandardRowsWithBystanders(): array
    {
        ['user' => $user] = $this->seedStandardRows();

        $mergedRow = Exercise::query()->where('canonical_name', $this->idsOf('merge')[1])->firstOrFail();
        $softDeletedOnly = User::factory()->create();
        LiftLog::factory()->create(['exercise_id' => $mergedRow->id, 'user_id' => $softDeletedOnly->id])->delete();

        $untouchedRow = Exercise::query()->where('canonical_name', self::UNTOUCHED[0])->firstOrFail();
        $unaffected = User::factory()->create();
        LiftLog::factory()->create(['exercise_id' => $untouchedRow->id, 'user_id' => $unaffected->id]);

        return ['user' => $user, 'softDeletedOnly' => $softDeletedOnly, 'unaffected' => $unaffected];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function signalRows(): array
    {
        return RehydrationSignal::query()->orderBy('id')->get()->map->getAttributes()->all();
    }

    #[Test]
    public function a_run_that_merges_raises_one_rehydration_signal_per_affected_user(): void
    {
        ['user' => $user, 'softDeletedOnly' => $softDeletedOnly, 'unaffected' => $unaffected] = $this->seedStandardRowsWithBystanders();
        $this->assertGreaterThan(1, count($this->idsOf('merge')));

        $this->migration()->up();

        $this->assertSame(1, RehydrationSignal::query()->count());
        $this->assertDatabaseHas('rehydration_signals', ['user_id' => $user->id, 'reason' => 'exercise-merge']);
        $this->assertDatabaseMissing('rehydration_signals', ['user_id' => $softDeletedOnly->id]);
        $this->assertDatabaseMissing('rehydration_signals', ['user_id' => $unaffected->id]);
        $this->assertDatabaseMissing('rehydration_signals', ['user_id' => null]);
    }

    #[Test]
    public function a_second_run_raises_no_rehydration_signal(): void
    {
        $this->seedStandardRowsWithBystanders();
        $this->migration()->up();
        $signalsAfterFirstRun = $this->signalRows();
        $this->assertNotEmpty($signalsAfterFirstRun);

        Carbon::setTestNow(now()->addHour());
        try {
            $this->migration()->up();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame($signalsAfterFirstRun, $this->signalRows());
    }

    #[Test]
    public function a_refused_file_raises_no_rehydration_signal(): void
    {
        $this->seedStandardRowsWithBystanders();
        Exercise::query()->where('canonical_name', $this->idsOf('update')[0])->firstOrFail()->forceDelete();

        try {
            $this->migration()->up();
            $this->fail('The migration applied a file that names a row no standard exercise has.');
        } catch (ExerciseLibraryOpsRefusedException) {
        }

        $this->assertSame(0, RehydrationSignal::query()->count());
    }
}
