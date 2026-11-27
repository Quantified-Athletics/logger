<?php

namespace Tests\Unit\Services;

use App\Models\Exercise;
use App\Models\ExerciseIntelligence;
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
     * Helper to create exercise with intelligence
     */
    private function createExerciseWithIntelligence(string $title, string $archetype, string $category): Exercise
    {
        $exercise = Exercise::factory()->create(['title' => $title]);
        
        ExerciseIntelligence::create([
            'exercise_id' => $exercise->id,
            'muscle_data' => json_encode([]),
            'primary_mover' => 'test_muscle',
            'largest_muscle' => 'test_muscle',
            'movement_archetype' => $archetype,
            'category' => $category,
            'difficulty_level' => 3,
        ]);
        
        return $exercise;
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
    public function it_lists_exercise_names_for_single_exercise_without_intelligence()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        $exercise = Exercise::factory()->create(['title' => 'Back Squat']);
        $this->addExerciseToWorkout($workout, $exercise);

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Back Squat', $label);
    }

    /** @test */
    public function it_lists_exercise_names_for_two_exercises_without_intelligence()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        $exercise1 = Exercise::factory()->create(['title' => 'Back Squat']);
        $exercise2 = Exercise::factory()->create(['title' => 'Deadlift']);
        $this->addExerciseToWorkout($workout, $exercise1);
        $this->addExerciseToWorkout($workout, $exercise2);

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Back Squat & Deadlift', $label);
    }

    /** @test */
    public function it_lists_the_exercise_name_for_a_single_exercise_with_intelligence()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        $exercise = $this->createExerciseWithIntelligence('Back Squat', 'squat', 'strength');
        $this->addExerciseToWorkout($workout, $exercise);

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Back Squat', $label);
    }

    /** @test */
    public function it_lists_exercise_names_for_two_exercises_with_intelligence()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        $exercise1 = $this->createExerciseWithIntelligence('Pull-Up', 'pull', 'strength');
        $exercise2 = $this->createExerciseWithIntelligence('Bent-Over Row', 'pull', 'strength');
        $this->addExerciseToWorkout($workout, $exercise1);
        $this->addExerciseToWorkout($workout, $exercise2);

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Pull-Up & Bent-Over Row', $label);
    }

    /** @test */
    public function it_uses_the_name_fallback_for_push_exercises_with_intelligence()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        $exercises = [
            $this->createExerciseWithIntelligence('Bench Press', 'push', 'strength'),
            $this->createExerciseWithIntelligence('Overhead Press', 'push', 'strength'),
            $this->createExerciseWithIntelligence('Push Ups', 'push', 'strength'),
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Upper Body • 3 exercises', $label);
    }

    /** @test */
    public function it_uses_the_mixed_fallback_for_pull_exercises_with_intelligence()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        $exercises = [
            $this->createExerciseWithIntelligence('Pull Ups', 'pull', 'strength'),
            $this->createExerciseWithIntelligence('Rows', 'pull', 'strength'),
            $this->createExerciseWithIntelligence('Lat Pulldown', 'pull', 'strength'),
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Mixed Workout • 3 exercises', $label);
    }

    /** @test */
    public function it_uses_the_name_fallback_for_squat_exercises_with_intelligence()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        $exercises = [
            $this->createExerciseWithIntelligence('Back Squat', 'squat', 'strength'),
            $this->createExerciseWithIntelligence('Front Squat', 'squat', 'strength'),
            $this->createExerciseWithIntelligence('Goblet Squat', 'squat', 'strength'),
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Leg Day • 3 exercises', $label);
    }

    /** @test */
    public function it_uses_the_name_fallback_for_a_push_and_pull_mix_with_intelligence()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        $exercises = [
            $this->createExerciseWithIntelligence('Bench Press', 'push', 'strength'),
            $this->createExerciseWithIntelligence('Pull Ups', 'pull', 'strength'),
            $this->createExerciseWithIntelligence('Overhead Press', 'push', 'strength'),
            $this->createExerciseWithIntelligence('Rows', 'pull', 'strength'),
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Upper Body • 4 exercises', $label);
    }

    /** @test */
    public function it_uses_the_name_fallback_for_a_squat_and_hinge_mix_with_intelligence()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        $exercises = [
            $this->createExerciseWithIntelligence('Back Squat', 'squat', 'strength'),
            $this->createExerciseWithIntelligence('Deadlift', 'hinge', 'strength'),
            $this->createExerciseWithIntelligence('Front Squat', 'squat', 'strength'),
            $this->createExerciseWithIntelligence('Romanian Deadlift', 'hinge', 'strength'),
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Leg Day • 4 exercises', $label);
    }

    /** @test */
    public function it_uses_the_name_fallback_for_mixed_archetypes_with_intelligence()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        $exercises = [
            $this->createExerciseWithIntelligence('Bench Press', 'push', 'strength'),
            $this->createExerciseWithIntelligence('Deadlift', 'hinge', 'strength'),
            $this->createExerciseWithIntelligence('Pull Ups', 'pull', 'strength'),
            $this->createExerciseWithIntelligence('Back Squat', 'squat', 'strength'),
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Leg Day • 4 exercises', $label);
    }

    /** @test */
    public function it_does_not_append_a_cardio_category_from_intelligence()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        $exercises = [
            $this->createExerciseWithIntelligence('Running', 'core', 'cardio'),
            $this->createExerciseWithIntelligence('Cycling', 'core', 'cardio'),
            $this->createExerciseWithIntelligence('Rowing', 'pull', 'cardio'),
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Mixed Workout • 3 exercises', $label);
        $this->assertStringNotContainsString('Cardio', $label);
    }

    /** @test */
    public function it_does_not_append_a_strength_category_from_intelligence()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        $exercises = [
            $this->createExerciseWithIntelligence('Bench Press', 'push', 'strength'),
            $this->createExerciseWithIntelligence('Overhead Press', 'push', 'strength'),
            $this->createExerciseWithIntelligence('Dips', 'push', 'strength'),
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Upper Body • 3 exercises', $label);
        $this->assertStringNotContainsString('Strength', $label);
    }

    /** @test */
    public function it_does_not_append_a_plyometric_category_from_intelligence()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        $exercises = [
            $this->createExerciseWithIntelligence('Box Jumps', 'squat', 'plyometric'),
            $this->createExerciseWithIntelligence('Burpees', 'core', 'plyometric'),
            $this->createExerciseWithIntelligence('Jump Squats', 'squat', 'plyometric'),
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Leg Day • 3 exercises', $label);
        $this->assertStringNotContainsString('Plyometric', $label);
    }

    /** @test */
    public function it_uses_the_mixed_fallback_for_core_exercises_with_intelligence()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        $exercises = [
            $this->createExerciseWithIntelligence('Plank', 'core', 'strength'),
            $this->createExerciseWithIntelligence('Crunches', 'core', 'strength'),
            $this->createExerciseWithIntelligence('Russian Twists', 'core', 'strength'),
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Mixed Workout • 3 exercises', $label);
    }

    /** @test */
    public function it_uses_fallback_for_exercises_without_intelligence()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        $exercises = [
            Exercise::factory()->create(['title' => 'Custom Exercise 1']),
            Exercise::factory()->create(['title' => 'Custom Exercise 2']),
            Exercise::factory()->create(['title' => 'Custom Exercise 3']),
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Mixed Workout • 3 exercises', $label);
    }

    /** @test */
    public function it_infers_leg_day_from_exercise_names_without_intelligence()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        $exercises = [
            Exercise::factory()->create(['title' => 'Squat Variations']),
            Exercise::factory()->create(['title' => 'Leg Press']),
            Exercise::factory()->create(['title' => 'Leg Curls']),
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Leg Day • 3 exercises', $label);
    }

    /** @test */
    public function it_uses_the_name_fallback_for_lunges_and_hinges_with_intelligence()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        $exercises = [
            $this->createExerciseWithIntelligence('Walking Lunges', 'squat', 'strength'),
            $this->createExerciseWithIntelligence('Deadlift', 'hinge', 'strength'),
            $this->createExerciseWithIntelligence('Bulgarian Split Squat', 'squat', 'strength'),
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Leg Day • 3 exercises', $label);
    }

    /** @test */
    public function it_uses_the_name_fallback_for_five_leg_exercises_with_intelligence()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        $exercises = [
            $this->createExerciseWithIntelligence('Back Squat', 'squat', 'strength'),
            $this->createExerciseWithIntelligence('Deadlift', 'hinge', 'strength'),
            $this->createExerciseWithIntelligence('Front Squat', 'squat', 'strength'),
            $this->createExerciseWithIntelligence('Romanian Deadlift', 'hinge', 'strength'),
            $this->createExerciseWithIntelligence('Plank', 'core', 'strength'), // 20% non-leg
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Leg Day • 5 exercises', $label);
    }

    /** @test */
    public function it_uses_the_name_fallback_for_mixed_categories_with_intelligence()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        $exercises = [
            $this->createExerciseWithIntelligence('Bench Press', 'push', 'strength'),
            $this->createExerciseWithIntelligence('Overhead Press', 'push', 'strength'),
            $this->createExerciseWithIntelligence('Burpees', 'push', 'cardio'),
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Upper Body • 3 exercises', $label);
    }

    /** @test */
    public function it_uses_the_name_fallback_when_only_some_exercises_have_intelligence()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        // 2 exercises with intelligence (squat + hinge), 1 without
        $exercises = [
            $this->createExerciseWithIntelligence('Back Squat', 'squat', 'strength'),
            Exercise::factory()->create(['title' => 'Back Rack Lunge']), // No intelligence
            $this->createExerciseWithIntelligence('Romanian Deadlift', 'hinge', 'strength'),
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Leg Day • 3 exercises', $label);
    }

    /** @test */
    public function it_uses_the_name_fallback_for_push_and_core_with_intelligence()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        $exercises = [
            $this->createExerciseWithIntelligence('Bench Press', 'push', 'strength'),
            $this->createExerciseWithIntelligence('Overhead Press', 'push', 'strength'),
            $this->createExerciseWithIntelligence('Plank', 'core', 'strength'),
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Upper Body • 3 exercises', $label);
    }

    /** @test */
    public function it_uses_the_mixed_fallback_for_core_only_workouts_with_intelligence()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        $exercises = [
            $this->createExerciseWithIntelligence('Plank', 'core', 'strength'),
            $this->createExerciseWithIntelligence('Crunches', 'core', 'strength'),
            $this->createExerciseWithIntelligence('Russian Twists', 'core', 'strength'),
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Mixed Workout • 3 exercises', $label);
    }

    /** @test */
    public function it_uses_the_name_fallback_for_upper_leg_and_core_with_intelligence()
    {
        $workout = Workout::factory()->create(['user_id' => $this->user->id]);
        
        $exercises = [
            $this->createExerciseWithIntelligence('Bench Press', 'push', 'strength'),
            $this->createExerciseWithIntelligence('Back Squat', 'squat', 'strength'),
            $this->createExerciseWithIntelligence('Plank', 'core', 'strength'),
            $this->createExerciseWithIntelligence('Pull-Up', 'pull', 'strength'),
        ];
        
        foreach ($exercises as $exercise) {
            $this->addExerciseToWorkout($workout, $exercise);
        }

        $label = $this->generator->generateFromWorkout($workout);

        $this->assertEquals('Leg Day • 4 exercises', $label);
    }
}
