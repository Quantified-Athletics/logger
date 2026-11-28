<?php

namespace Tests\Unit\Sync;

use App\Models\Exercise;
use App\Models\ExerciseAlias;
use App\Models\User;
use App\Sync\Services\ExerciseResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExerciseResolverServiceTest extends TestCase
{
    use RefreshDatabase;

    private ExerciseResolverService $resolver;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ExerciseResolverService;
        $this->user = User::factory()->create();
    }

    public function test_exact_canonical_name_match(): void
    {
        $exercise = Exercise::create([
            'name' => 'Bench Press',
            'canonical_name' => 'bench_press',
            'log_type' => 'barbell',
        ]);

        $resolved = $this->resolver->resolve('Bench Press', $this->user);
        $this->assertEquals($exercise->id, $resolved->id);

        $resolvedSnake = $this->resolver->resolve('bench_press', $this->user);
        $this->assertEquals($exercise->id, $resolvedSnake->id);
    }

    public function test_case_insensitive_title_match(): void
    {
        $exercise = Exercise::create([
            'name' => 'Deadlift',
            'canonical_name' => 'deadlift',
            'log_type' => 'barbell',
        ]);

        $resolved = $this->resolver->resolve('deadlift', $this->user);
        $this->assertEquals($exercise->id, $resolved->id);

        $resolvedUpper = $this->resolver->resolve('DEADLIFT', $this->user);
        $this->assertEquals($exercise->id, $resolvedUpper->id);
    }

    public function test_case_insensitive_alias_match(): void
    {
        $exercise = Exercise::create([
            'name' => 'Back Squat',
            'canonical_name' => 'back_squat',
            'log_type' => 'barbell',
        ]);

        ExerciseAlias::create([
            'exercise_id' => $exercise->id,
            'alias_name' => 'Squats',
            'user_id' => null, // Sync aliases must be global
        ]);

        $resolved = $this->resolver->resolve('squats', $this->user);
        $this->assertEquals($exercise->id, $resolved->id);

        $resolvedCaps = $this->resolver->resolve('SQUATS', $this->user);
        $this->assertEquals($exercise->id, $resolvedCaps->id);
    }

    public function test_scoping_to_user_and_global_promotion(): void
    {
        // 1. User specific exercise
        $userExercise = Exercise::create([
            'name' => 'User Push Up',
            'canonical_name' => 'user_push_up',
            'log_type' => 'bodyweight',
            'user_id' => $this->user->id,
        ]);

        // 2. Another user specific exercise
        $otherUser = User::factory()->create();
        $otherExercise = Exercise::create([
            'name' => 'Other Push Up',
            'canonical_name' => 'other_push_up',
            'log_type' => 'bodyweight',
            'user_id' => $otherUser->id,
        ]);

        // Resolving user's own user-owned exercise doesn't match global, but promotes it to global
        $resolved = $this->resolver->resolve('user_push_up', $this->user);
        $this->assertEquals($userExercise->id, $resolved->id);
        $this->assertNull($resolved->fresh()->user_id); // Promoted to global

        // Resolving other user's user-owned exercise doesn't match global, but promotes it to global
        $resolvedOther = $this->resolver->resolve('other_push_up', $this->user);
        $this->assertEquals($otherExercise->id, $resolvedOther->id);
        $this->assertNull($resolvedOther->fresh()->user_id); // Promoted to global
    }

    public function test_resolve_with_canonical_name_param(): void
    {
        $exercise = Exercise::create([
            'name' => 'Barbell Bench Press',
            'canonical_name' => 'bb_bench_press',
            'log_type' => 'barbell',
        ]);

        // Should resolve using exact canonical name parameter
        $resolved = $this->resolver->resolve('Mismatched Title', $this->user, 'barbell', 'bb_bench_press');
        $this->assertEquals($exercise->id, $resolved->id);
        $this->assertEquals('Mismatched Title', $resolved->fresh()->name); // Title updated because it differed
    }

    public function test_resolve_updates_mismatched_title(): void
    {
        $exercise = Exercise::create([
            'name' => 'Old Title',
            'canonical_name' => 'bench_press',
            'log_type' => 'barbell',
        ]);

        $resolved = $this->resolver->resolve('New Title', $this->user, null, 'bench_press');
        $this->assertEquals($exercise->id, $resolved->id);
        $this->assertEquals('New Title', $resolved->fresh()->name);
    }

    public function test_soft_deleted_exclusion(): void
    {
        $exercise = Exercise::create([
            'name' => 'Soft Deleted Pull Up',
            'canonical_name' => 'soft_deleted_pull_up',
            'log_type' => 'bodyweight',
        ]);

        $exercise->delete(); // Soft delete

        // Should auto-create a new one instead of returning soft-deleted
        $resolved = $this->resolver->resolve('Soft Deleted Pull Up', $this->user);
        $this->assertNotEquals($exercise->id, $resolved->id);
        $this->assertNull($resolved->deleted_at);
    }

    public function test_auto_creation_with_type_derivation(): void
    {
        // cardio log type should derive cardio exercise type
        $cardio = $this->resolver->resolve('Super Run', $this->user, 'cardio');
        $this->assertEquals('Super Run', $cardio->name);
        $this->assertEquals('cardio', $cardio->strategyTypeName());

        // bodyweight log type should derive bodyweight exercise type
        $bodyweight = $this->resolver->resolve('Super Push', $this->user, 'bodyweight');
        $this->assertEquals('bodyweight', $bodyweight->strategyTypeName());

        // barbell and barbell-complex log types should derive regular exercise type
        $regular = $this->resolver->resolve('Super Press', $this->user, 'barbell');
        $this->assertEquals('regular', $regular->strategyTypeName());

        $barbellComplex = $this->resolver->resolve('Bear Complex', $this->user, 'barbell-complex');
        $this->assertEquals('regular', $barbellComplex->strategyTypeName());

        // weighted-carry 5 logTypes should derive load_output exercise type
        foreach (['weighted-carry-1-kb', 'weighted-carry-2-kb', 'weighted-carry-1-db', 'weighted-carry-2-db', 'weighted-carry-ball'] as $logType) {
            $carry = $this->resolver->resolve("Farmer Carry $logType", $this->user, $logType);
            $this->assertEquals('load_output', $carry->strategyTypeName());
        }

        // static-hold should derive static_hold exercise type
        $hold = $this->resolver->resolve('L-Sit Hold', $this->user, 'static-hold');
        $this->assertEquals('static_hold', $hold->strategyTypeName());

        // timed-reps should derive timed_output exercise type
        $timedReps = $this->resolver->resolve('Glute Bridge March', $this->user, 'timed-reps');
        $this->assertEquals('timed_output', $timedReps->strategyTypeName());
    }
}
