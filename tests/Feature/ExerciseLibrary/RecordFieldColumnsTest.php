<?php

namespace Tests\Feature\ExerciseLibrary;

use App\ExerciseLibrary\LogTypeRegistry;
use App\Models\Exercise;
use App\Models\User;
use App\Services\ExerciseTypes\LogTypeTable;
use Database\Seeders\GlobalExercisesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * M7 step 1 (#42): Logger carries every field of the shared exercise record as a column, named by
 * the snake_case rule. The record's `id` is `canonical_name`, `name` is `name`, and `aliases` live in
 * `exercise_aliases`, so those three have no column of their own. Every standard exercise's
 * `log_type` is one the shared log type registry lists.
 */
class RecordFieldColumnsTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_11_28_120000_add_record_fields_to_exercises_table.php';

    /**
     * The shared record's fields (§5.2), in the order the issue lists them.
     */
    private const RECORD_FIELDS = [
        'id', 'name', 'aliases', 'formerIds', 'logType', 'equipment', 'primaryMuscles', 'coreMuscles',
        'regionsLoaded', 'primaryMovements', 'coreMovements', 'motion', 'modalities', 'kinds', 'laterality',
        'families', 'category', 'maxPerWeek', 'repeatCooldown', 'suggestionCount', 'suggestionPeriod', 'video',
        'generatorEligible', 'historicReps', 'modifier', 'createdAt', 'members', 'bucket', 'tags',
    ];

    /**
     * The fields with no column of their own.
     */
    private const FIELDS_WITHOUT_A_COLUMN = ['id', 'name', 'aliases'];

    /**
     * The columns this step adds: every record field Logger lacked.
     */
    private const ADDED_COLUMNS = [
        'former_ids', 'equipment', 'primary_muscles', 'core_muscles', 'regions_loaded', 'primary_movements',
        'core_movements', 'motion', 'modalities', 'kinds', 'laterality', 'families', 'category', 'max_per_week',
        'repeat_cooldown', 'suggestion_count', 'suggestion_period', 'video', 'generator_eligible',
        'historic_reps', 'modifier', 'members', 'bucket', 'tags',
    ];

    /**
     * The columns holding a list, an either-or list or a map.
     */
    private const JSON_COLUMNS = [
        'former_ids', 'equipment', 'primary_muscles', 'core_muscles', 'regions_loaded', 'primary_movements',
        'core_movements', 'modalities', 'kinds', 'families', 'historic_reps', 'members', 'tags',
    ];

    #[Test]
    public function every_record_field_but_id_name_and_aliases_has_its_snake_case_column(): void
    {
        $missing = [];

        foreach (array_diff(self::RECORD_FIELDS, self::FIELDS_WITHOUT_A_COLUMN) as $field) {
            $column = $this->snakeCase($field);
            if (! Schema::hasColumn('exercises', $column)) {
                $missing[] = "{$field} → {$column}";
            }
        }

        $this->assertSame([], $missing, 'These record fields have no column on exercises.');
    }

    #[Test]
    public function the_snake_case_rule_names_the_columns_the_issue_names(): void
    {
        $this->assertSame('log_type', $this->snakeCase('logType'));
        $this->assertSame('primary_muscles', $this->snakeCase('primaryMuscles'));
        $this->assertSame('former_ids', $this->snakeCase('formerIds'));
        $this->assertSame('max_per_week', $this->snakeCase('maxPerWeek'));
    }

    #[Test]
    public function every_added_column_is_nullable_so_existing_rows_keep_working(): void
    {
        $columns = collect(Schema::getColumns('exercises'))->keyBy('name');

        foreach (self::ADDED_COLUMNS as $column) {
            $this->assertTrue($columns->has($column), "{$column} is missing.");
            $this->assertTrue($columns[$column]['nullable'], "{$column} must be nullable.");
        }
    }

    #[Test]
    public function an_existing_exercise_reads_every_added_field_as_absent(): void
    {
        $exercise = Exercise::create(['name' => 'Back Squat'])->fresh();

        foreach (self::ADDED_COLUMNS as $column) {
            $this->assertNull($exercise->{$column}, "{$column} should be absent on a row that never set it.");
        }
    }

    #[Test]
    public function the_json_columns_round_trip_lists_either_or_lists_and_maps(): void
    {
        $values = [
            'former_ids' => ['air_squats', 'bodyweight_squat'],
            'equipment' => [['plate', 'kettlebell', 'dumbbells'], 'bench'],
            'primary_muscles' => ['quads', 'glutes'],
            'core_muscles' => ['rectus_abdominis'],
            'regions_loaded' => ['lower-back' => 'heavy', 'hands' => 'light'],
            'primary_movements' => ['squat'],
            'core_movements' => ['rotational'],
            'modalities' => ['strength'],
            'kinds' => ['gymnastics'],
            'families' => ['burpees'],
            'historic_reps' => ['10', '10-15', '20'],
            'members' => ['clean_deadlift', 'clean_pull', 'power_clean'],
            'tags' => ['warmup'],
        ];

        $this->assertSame(self::JSON_COLUMNS, array_keys($values));

        $exercise = Exercise::create(['name' => 'Air Squat', ...$values]);

        $fresh = Exercise::findOrFail($exercise->id);
        foreach ($values as $column => $value) {
            $this->assertSame($value, $fresh->{$column}, "{$column} did not round-trip.");
        }
    }

    #[Test]
    public function the_scalar_columns_round_trip(): void
    {
        $values = [
            'motion' => 'grind',
            'laterality' => 'unilateral',
            'category' => 'Core · Rotation',
            'max_per_week' => 3,
            'repeat_cooldown' => 0,
            'suggestion_count' => 2,
            'suggestion_period' => 'week',
            'video' => 'https://www.youtube.com/watch?v=OCg3UXgzftc',
            'generator_eligible' => true,
            'modifier' => '/side',
            'bucket' => 'clean_complex',
        ];

        $exercise = Exercise::create(['name' => 'Seated Ab Twist', ...$values]);

        $fresh = Exercise::findOrFail($exercise->id);
        foreach ($values as $column => $value) {
            $this->assertSame($value, $fresh->{$column}, "{$column} did not round-trip.");
        }

        $fresh->update(['generator_eligible' => false]);
        $this->assertFalse($fresh->fresh()->generator_eligible);
    }

    #[Test]
    public function setting_a_column_to_null_clears_it(): void
    {
        $exercise = Exercise::create(['name' => 'Air Squat', 'primary_muscles' => ['quads'], 'motion' => 'grind']);

        $exercise->update(['primary_muscles' => null, 'motion' => null]);

        $fresh = $exercise->fresh();
        $this->assertNull($fresh->primary_muscles);
        $this->assertNull($fresh->motion);
    }

    #[Test]
    public function the_activity_log_keeps_the_old_value_of_a_record_field(): void
    {
        $exercise = Exercise::create(['name' => 'Air Squat', 'primary_muscles' => ['quads']]);
        $exercise->update(['primary_muscles' => ['quads', 'glutes'], 'max_per_week' => 3]);

        $changes = $exercise->activities()->latest('id')->first()->properties;

        $this->assertSame(['quads'], $changes['old']['primary_muscles']);
        $this->assertSame(['quads', 'glutes'], $changes['attributes']['primary_muscles']);
        $this->assertNull($changes['old']['max_per_week']);
        $this->assertSame(3, $changes['attributes']['max_per_week']);
    }

    #[Test]
    public function rolling_back_drops_the_added_columns_and_keeps_the_rows(): void
    {
        $exercise = Exercise::create(['name' => 'Air Squat', 'primary_muscles' => ['quads']]);

        $migration = require base_path(self::MIGRATION);
        $migration->down();

        foreach (self::ADDED_COLUMNS as $column) {
            $this->assertFalse(Schema::hasColumn('exercises', $column), "{$column} survived the rollback.");
        }
        $this->assertSame('Air Squat', Exercise::query()->toBase()->where('id', $exercise->id)->value('name'));

        $migration->up();

        $this->assertNull($exercise->fresh()->primary_muscles);
    }

    #[Test]
    public function the_registry_lists_the_shared_log_types(): void
    {
        $ids = app(LogTypeRegistry::class)->ids();

        $this->assertContains('barbell', $ids);
        $this->assertContains('weighted-carry-2-kb', $ids);
        $this->assertContains('cardio-calories', $ids);
        $this->assertNotContains('regular', $ids);
        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    #[Test]
    public function every_log_type_logger_can_assign_is_in_the_registry(): void
    {
        $registry = app(LogTypeRegistry::class);

        $outside = array_values(array_filter(
            [...LogTypeTable::logTypes(), LogTypeTable::DEFAULT_LOG_TYPE],
            fn (string $logType): bool => ! $registry->contains($logType),
        ));

        $this->assertSame([], $outside, 'Logger can assign log types the shared registry does not list.');
    }

    #[Test]
    public function every_seeded_standard_exercise_has_a_log_type_from_the_registry(): void
    {
        $this->seed(GlobalExercisesSeeder::class);
        $this->assertGreaterThan(0, Exercise::global()->count());

        $this->assertSame(
            [],
            app(LogTypeRegistry::class)->standardExercisesOutside()->all(),
            'These standard exercises have a log type the shared registry does not list.',
        );
    }

    #[Test]
    public function a_standard_exercise_with_a_log_type_outside_the_registry_is_reported(): void
    {
        Exercise::create(['name' => 'Back Squat', 'log_type' => 'barbell']);
        Exercise::query()->toBase()->insert([
            'name' => 'Mystery Lift',
            'canonical_name' => 'mystery_lift',
            'log_type' => 'regular',
            'user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::factory()->create();
        Exercise::create(['name' => 'My Lift', 'user_id' => $user->id, 'log_type' => 'barbell']);

        $this->assertSame(['mystery_lift' => 'regular'], app(LogTypeRegistry::class)->standardExercisesOutside()->all());
    }

    #[Test]
    public function a_soft_deleted_standard_exercise_is_not_reported(): void
    {
        Exercise::query()->toBase()->insert([
            'name' => 'Retired Lift',
            'canonical_name' => 'retired_lift',
            'log_type' => 'regular',
            'user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => now(),
        ]);

        $this->assertSame([], app(LogTypeRegistry::class)->standardExercisesOutside()->all());
    }

    private function snakeCase(string $field): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $field));
    }
}
