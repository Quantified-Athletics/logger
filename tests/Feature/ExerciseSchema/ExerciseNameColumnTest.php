<?php

namespace Tests\Feature\ExerciseSchema;

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P4b step 1 (#35): the exercise's display-name column is `name`, the snake_case form of the shared
 * exercise record's field, instead of `title`. The rename keeps the per-scope unique constraint and
 * its data, and rolls back to `title`.
 */
class ExerciseNameColumnTest extends TestCase
{
    use RefreshDatabase;

    private const RENAME_MIGRATION = 'database/migrations/2026_11_28_000000_rename_title_to_name_on_exercises_table.php';

    private const UNIQUE_INDEX = 'unique_exercise_name_per_scope';

    #[Test]
    public function the_exercises_table_has_a_name_column_and_no_title_column(): void
    {
        $this->assertTrue(Schema::hasColumn('exercises', 'name'));
        $this->assertFalse(Schema::hasColumn('exercises', 'title'));
    }

    #[Test]
    public function the_per_scope_unique_constraint_is_on_name(): void
    {
        $this->assertSame(['name', 'user_id'], $this->uniqueIndexColumns());
    }

    #[Test]
    public function two_exercises_with_the_same_name_in_one_scope_are_refused(): void
    {
        $user = User::factory()->create();
        Exercise::create(['name' => 'Back Squat', 'user_id' => $user->id, 'canonical_name' => 'back_squat_a']);

        $this->expectException(UniqueConstraintViolationException::class);

        Exercise::create(['name' => 'Back Squat', 'user_id' => $user->id, 'canonical_name' => 'back_squat_b']);
    }

    #[Test]
    public function the_same_name_is_allowed_in_another_scope(): void
    {
        $user = User::factory()->create();
        Exercise::create(['name' => 'Back Squat', 'user_id' => null]);
        Exercise::create(['name' => 'Back Squat', 'user_id' => $user->id, 'canonical_name' => 'back_squat_mine']);

        $this->assertSame(2, Exercise::where('name', 'Back Squat')->count());
    }

    #[Test]
    public function rolling_back_renames_the_column_to_title_with_data_and_constraint_intact(): void
    {
        $exercise = Exercise::create(['name' => 'Romanian Deadlift']);

        $migration = require base_path(self::RENAME_MIGRATION);
        $migration->down();

        $this->assertTrue(Schema::hasColumn('exercises', 'title'));
        $this->assertFalse(Schema::hasColumn('exercises', 'name'));
        $this->assertSame(['title', 'user_id'], $this->uniqueIndexColumns());
        $this->assertSame('Romanian Deadlift', Exercise::query()->toBase()->where('id', $exercise->id)->value('title'));

        $migration->up();

        $this->assertSame(['name', 'user_id'], $this->uniqueIndexColumns());
        $this->assertSame('Romanian Deadlift', $exercise->fresh()->name);
    }

    #[Test]
    public function the_model_writes_name_and_derives_the_canonical_name_from_it(): void
    {
        $exercise = Exercise::create(['name' => 'Bulgarian Split Squat']);

        $this->assertSame('Bulgarian Split Squat', $exercise->fresh()->name);
        $this->assertSame('bulgarian_split_squat', $exercise->canonical_name);
    }

    #[Test]
    public function the_factory_writes_name(): void
    {
        $exercise = Exercise::factory()->create();

        $this->assertNotEmpty($exercise->fresh()->name);
    }

    #[Test]
    public function the_activity_log_records_name(): void
    {
        $exercise = Exercise::create(['name' => 'Hip Thrust']);
        $exercise->update(['name' => 'Barbell Hip Thrust']);

        $changes = $exercise->activities()->latest('id')->first()->properties;

        $this->assertSame('Barbell Hip Thrust', $changes['attributes']['name']);
        $this->assertSame('Hip Thrust', $changes['old']['name']);
        $this->assertArrayNotHasKey('title', $changes['attributes']);
    }

    #[Test]
    public function exercises_shown_without_global_ones_are_ordered_by_name(): void
    {
        $user = User::factory()->create(['show_global_exercises' => false]);
        Exercise::create(['name' => 'Zercher Squat', 'user_id' => $user->id, 'canonical_name' => 'zercher_squat']);
        Exercise::create(['name' => 'Arnold Press', 'user_id' => $user->id, 'canonical_name' => 'arnold_press']);

        $names = Exercise::availableToUser($user->id, false)->pluck('name')->all();

        $this->assertSame(['Arnold Press', 'Zercher Squat'], $names);
    }

    /**
     * @return list<string>
     */
    private function uniqueIndexColumns(): array
    {
        $index = collect(Schema::getIndexes('exercises'))->firstWhere('name', self::UNIQUE_INDEX);
        $this->assertNotNull($index, 'The per-scope unique constraint is missing.');
        $this->assertTrue($index['unique']);

        return $index['columns'];
    }
}
