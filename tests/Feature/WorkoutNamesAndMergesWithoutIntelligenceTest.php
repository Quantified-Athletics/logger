<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\ExerciseIntelligence;
use App\Models\Role;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutExercise;
use App\Services\WorkoutNameGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Workout names and exercise merges no longer read exercise intelligence (#31, step 2).
 */
class WorkoutNamesAndMergesWithoutIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);
        Role::firstOrCreate(['name' => 'Athlete']);

        $this->user = User::factory()->create();
    }

    /**
     * @return array<string, array{0: array<int, string>, 1: string, 2: string}>
     */
    public static function workoutSizes(): array
    {
        return [
            'one exercise' => [['Pull-Up'], 'Pull-Up', 'Pull-Up'],
            'two exercises' => [['Pull-Up', 'Barbell Row'], 'Pull-Up & Barbell Row', 'Pull-Up & Barbell Row'],
            'four exercises' => [
                ['Pull-Up', 'Barbell Row', 'Lat Pulldown', 'Face Pull'],
                'Mixed Workout • 4 exercises',
                'Mixed Workout',
            ],
        ];
    }

    /**
     * @param  array<int, string>  $titles
     */
    #[Test]
    #[DataProvider('workoutSizes')]
    public function workouts_index_shows_the_fallback_name_even_when_exercises_have_intelligence(array $titles, string $indexName, string $editName): void
    {
        $this->createWorkout($titles, withIntelligence: true);

        $response = $this->actingAs($this->user)->get(route('workouts.index'));

        $response->assertOk();
        $this->assertSame([$indexName], $this->tableLine1s($response));
    }

    /**
     * @param  array<int, string>  $titles
     */
    #[Test]
    #[DataProvider('workoutSizes')]
    public function workouts_index_name_is_the_same_with_and_without_intelligence(array $titles, string $indexName, string $editName): void
    {
        $this->createWorkout($titles, withIntelligence: false);

        $response = $this->actingAs($this->user)->get(route('workouts.index'));

        $response->assertOk();
        $this->assertSame([$indexName], $this->tableLine1s($response));
    }

    /**
     * @param  array<int, string>  $titles
     */
    #[Test]
    #[DataProvider('workoutSizes')]
    public function simple_workout_edit_title_is_the_fallback_name_even_when_exercises_have_intelligence(array $titles, string $indexName, string $editName): void
    {
        $workout = $this->createWorkout($titles, withIntelligence: true);

        $response = $this->actingAs($this->user)->get(route('workouts.edit-simple', $workout));

        $response->assertOk();
        $this->assertSame($editName, $this->titleMain($response));
    }

    /**
     * @param  array<int, string>  $titles
     */
    #[Test]
    #[DataProvider('workoutSizes')]
    public function simple_workout_edit_title_is_the_same_with_and_without_intelligence(array $titles, string $indexName, string $editName): void
    {
        $workout = $this->createWorkout($titles, withIntelligence: false);

        $response = $this->actingAs($this->user)->get(route('workouts.edit-simple', $workout));

        $response->assertOk();
        $this->assertSame($editName, $this->titleMain($response));
    }

    #[Test]
    public function generating_a_name_from_one_exercise_uses_the_date_fallback_even_with_intelligence(): void
    {
        $this->travelTo('2026-11-27 10:00:00');

        $exercise = $this->createExercise('Pull-Up', withIntelligence: true);

        $this->assertSame('New Workout - Nov 27, 2026', (new WorkoutNameGenerator)->generate($exercise));
    }

    #[Test]
    public function merging_an_exercise_with_intelligence_succeeds_and_does_not_move_the_intelligence(): void
    {
        $admin = $this->createAdmin();
        $source = $this->createExercise('User Pull-Up', withIntelligence: true, userId: $this->user->id);
        $target = Exercise::factory()->create(['title' => 'Pull-Up', 'user_id' => null]);

        $response = $this->actingAs($admin)->post(route('exercises.merge', $source), [
            'target_exercise_id' => $target->id,
        ]);

        $response->assertRedirect(route('exercises.index'));
        $this->assertSoftDeleted($source);
        $this->assertDatabaseMissing('exercise_intelligence', ['exercise_id' => $target->id]);
    }

    #[Test]
    public function merge_preview_renders_without_intelligence_insights(): void
    {
        $admin = $this->createAdmin();
        $source = $this->createExercise('User Pull-Up', withIntelligence: true, userId: $this->user->id);
        $this->createExercise('Pull-Up', withIntelligence: true);

        $response = $this->actingAs($admin)->get(route('exercises.show-merge', $source));

        $response->assertOk();
        $response->assertViewIs('exercises.merge');
        $response->assertSee('Pull-Up');
        $response->assertDontSee('Has AI insights');
        $response->assertDontSee('fa-brain', false);
    }

    #[Test]
    public function the_name_generator_merge_service_and_merge_view_do_not_name_intelligence(): void
    {
        $files = [
            'app/Services/WorkoutNameGenerator.php',
            'app/Services/ExerciseMergeService.php',
            'resources/views/exercises/merge.blade.php',
        ];

        foreach ($files as $file) {
            $this->assertDoesNotMatchRegularExpression(
                '/intelligence/i',
                file_get_contents(base_path($file)),
                "{$file} still names intelligence"
            );
        }
    }

    /**
     * @param  array<int, string>  $titles
     */
    private function createWorkout(array $titles, bool $withIntelligence): Workout
    {
        $workout = Workout::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Stored Name',
            'wod_syntax' => null,
        ]);

        foreach ($titles as $index => $title) {
            WorkoutExercise::create([
                'workout_id' => $workout->id,
                'exercise_id' => $this->createExercise($title, $withIntelligence)->id,
                'order' => $index + 1,
            ]);
        }

        return $workout;
    }

    private function createExercise(string $title, bool $withIntelligence, ?int $userId = null): Exercise
    {
        $exercise = Exercise::factory()->create(['title' => $title, 'user_id' => $userId]);

        if ($withIntelligence) {
            ExerciseIntelligence::create([
                'exercise_id' => $exercise->id,
                'muscle_data' => json_encode([]),
                'primary_mover' => 'latissimus_dorsi',
                'largest_muscle' => 'latissimus_dorsi',
                'movement_archetype' => 'pull',
                'category' => 'strength',
                'difficulty_level' => 3,
            ]);
        }

        return $exercise;
    }

    private function createAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('name', 'Admin')->first());

        return $admin;
    }

    /**
     * @return array<int, string>
     */
    private function tableLine1s(TestResponse $response): array
    {
        $table = collect($response->viewData('data')['components'])->firstWhere('type', 'table');
        $this->assertNotNull($table, 'The workouts index has no table');

        return collect($table['data']['rows'])->pluck('line1')->all();
    }

    private function titleMain(TestResponse $response): string
    {
        $title = collect($response->viewData('data')['components'])->firstWhere('type', 'title');
        $this->assertNotNull($title, 'The edit page has no title');

        return $title['data']['main'];
    }
}
