<?php

namespace Tests\Unit\Services;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutExercise;
use App\Services\WorkoutNameGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkoutLabelGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private WorkoutNameGenerator $generator;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new WorkoutNameGenerator();
        $this->user = User::factory()->create();
    }

    /**
     * Helper to create an exercise with a title
     */
    private function createExercise(string $title): Exercise
    {
        return Exercise::factory()->create(['name' => $title]);
    }

    /**
     * Helper to add exercise to workout
     */
    private function addExerciseToWorkout(Workout $workout, Exercise $exercise): void
    {
        WorkoutExercise::create([
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
            'order' => $workout->exercises()->count() + 1,
        ]);
    }

    /** @test */
    public function it_returns_empty_workout_for_workout_with_no_exercises()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Empty Workout', $label);
    }

    /** @test */
    public function it_lists_exercise_names_for_single_exercise()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        $exercise = Exercise::factory()->create(['name' => 'Back Squat']);
        $this->addExerciseToWorkout($workout, $exercise);

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Back Squat', $label);
    }

    /** @test */
    public function it_lists_exercise_names_for_two_exercises()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        $exercise1 = Exercise::factory()->create(['name' => 'Back Squat']);
        $exercise2 = Exercise::factory()->create(['name' => 'Deadlift']);
        $this->addExerciseToWorkout($workout, $exercise1);
        $this->addExerciseToWorkout($workout, $exercise2);

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Back Squat & Deadlift', $label);
    }

    /** @test */
    public function it_lists_the_exercise_name_for_a_single_exercise()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        $exercise = $this->createExercise('Back Squat');
        $this->addExerciseToWorkout($workout, $exercise);

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Back Squat', $label);
    }

    /** @test */
    public function it_lists_exercise_names_for_two_pull_exercises()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        $exercise1 = $this->createExercise('Pull-Up');
        $exercise2 = $this->createExercise('Bent-Over Row');
        $this->addExerciseToWorkout($workout, $exercise1);
        $this->addExerciseToWorkout($workout, $exercise2);

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Pull-Up & Bent-Over Row', $label);
    }

    /** @test */
    public function it_uses_the_name_fallback_for_push_exercises()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        $exercises = [
            $this->createExercise('Bench Press'),
            $this->createExercise('Overhead Press'),
            $this->createExercise('Push Ups'),
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Upper Body • 3 exercises', $label);
    }

    /** @test */
    public function it_uses_the_mixed_fallback_for_pull_exercises()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        $exercises = [
            $this->createExercise('Pull Ups'),
            $this->createExercise('Rows'),
            $this->createExercise('Lat Pulldown'),
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Mixed Workout • 3 exercises', $label);
    }

    /** @test */
    public function it_uses_the_name_fallback_for_squat_exercises()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        $exercises = [
            $this->createExercise('Back Squat'),
            $this->createExercise('Front Squat'),
            $this->createExercise('Goblet Squat'),
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Leg Day • 3 exercises', $label);
    }

    /** @test */
    public function it_uses_the_name_fallback_for_a_push_and_pull_mix()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        $exercises = [
            $this->createExercise('Bench Press'),
            $this->createExercise('Pull Ups'),
            $this->createExercise('Overhead Press'),
            $this->createExercise('Rows'),
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Upper Body • 4 exercises', $label);
    }

    /** @test */
    public function it_uses_the_name_fallback_for_a_squat_and_hinge_mix()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        $exercises = [
            $this->createExercise('Back Squat'),
            $this->createExercise('Deadlift'),
            $this->createExercise('Front Squat'),
            $this->createExercise('Romanian Deadlift'),
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Leg Day • 4 exercises', $label);
    }

    /** @test */
    public function it_uses_the_name_fallback_for_mixed_archetypes()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        $exercises = [
            $this->createExercise('Bench Press'),
            $this->createExercise('Deadlift'),
            $this->createExercise('Pull Ups'),
            $this->createExercise('Back Squat'),
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Leg Day • 4 exercises', $label);
    }

    /** @test */
    public function it_does_not_append_a_cardio_category()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        $exercises = [
            $this->createExercise('Running'),
            $this->createExercise('Cycling'),
            $this->createExercise('Rowing'),
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Mixed Workout • 3 exercises', $label);
        $this->assertStringNotContainsString('Cardio', $label);
    }

    /** @test */
    public function it_does_not_append_a_strength_category()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        $exercises = [
            $this->createExercise('Bench Press'),
            $this->createExercise('Overhead Press'),
            $this->createExercise('Dips'),
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Upper Body • 3 exercises', $label);
        $this->assertStringNotContainsString('Strength', $label);
    }

    /** @test */
    public function it_does_not_append_a_plyometric_category()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        $exercises = [
            $this->createExercise('Box Jumps'),
            $this->createExercise('Burpees'),
            $this->createExercise('Jump Squats'),
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Leg Day • 3 exercises', $label);
        $this->assertStringNotContainsString('Plyometric', $label);
    }

    /** @test */
    public function it_uses_the_mixed_fallback_for_core_exercises()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        $exercises = [
            $this->createExercise('Plank'),
            $this->createExercise('Crunches'),
            $this->createExercise('Russian Twists'),
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Mixed Workout • 3 exercises', $label);
    }

    /** @test */
    public function it_uses_fallback_for_exercises()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        $exercises = [
            Exercise::factory()->create(['name' => 'Custom Exercise 1']),
            Exercise::factory()->create(['name' => 'Custom Exercise 2']),
            Exercise::factory()->create(['name' => 'Custom Exercise 3']),
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Mixed Workout • 3 exercises', $label);
    }

    /** @test */
    public function it_infers_leg_day_from_exercise_names()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        $exercises = [
            Exercise::factory()->create(['name' => 'Squat Variations']),
            Exercise::factory()->create(['name' => 'Leg Press']),
            Exercise::factory()->create(['name' => 'Leg Curls']),
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Leg Day • 3 exercises', $label);
    }

    /** @test */
    public function it_uses_the_name_fallback_for_lunges_and_hinges()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        $exercises = [
            $this->createExercise('Walking Lunges'),
            $this->createExercise('Deadlift'),
            $this->createExercise('Bulgarian Split Squat'),
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Leg Day • 3 exercises', $label);
    }

    /** @test */
    public function it_uses_the_name_fallback_for_five_leg_exercises()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        $exercises = [
            $this->createExercise('Back Squat'),
            $this->createExercise('Deadlift'),
            $this->createExercise('Front Squat'),
            $this->createExercise('Romanian Deadlift'),
            $this->createExercise('Plank'), // 20% non-leg
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Leg Day • 5 exercises', $label);
    }

    /** @test */
    public function it_uses_the_name_fallback_for_mixed_categories()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        $exercises = [
            $this->createExercise('Bench Press'),
            $this->createExercise('Overhead Press'),
            $this->createExercise('Burpees'),
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Upper Body • 3 exercises', $label);
    }

    /** @test */
    public function it_uses_the_keyword_fallback_for_a_mixed_leg_workout()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        $exercises = [
            $this->createExercise('Back Squat'),
            $this->createExercise('Back Rack Lunge'),
            $this->createExercise('Romanian Deadlift'),
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Leg Day • 3 exercises', $label);
    }

    /** @test */
    public function it_uses_the_name_fallback_for_push_and_core()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        $exercises = [
            $this->createExercise('Bench Press'),
            $this->createExercise('Overhead Press'),
            $this->createExercise('Plank'),
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Upper Body • 3 exercises', $label);
    }

    /** @test */
    public function it_uses_the_mixed_fallback_for_core_only_workouts()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        $exercises = [
            $this->createExercise('Plank'),
            $this->createExercise('Crunches'),
            $this->createExercise('Russian Twists'),
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Mixed Workout • 3 exercises', $label);
    }

    /** @test */
    public function it_uses_the_name_fallback_for_upper_leg_and_core()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        $exercises = [
            $this->createExercise('Bench Press'),
            $this->createExercise('Back Squat'),
            $this->createExercise('Plank'),
            $this->createExercise('Pull-Up'),
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Leg Day • 4 exercises', $label);
    }
}
