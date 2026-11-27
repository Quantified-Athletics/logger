<?php

namespace Tests\Unit;

use App\Models\Exercise;
use App\Models\LiftLog;
use App\Models\Role;
use App\Models\User;
use App\Services\ExerciseMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ExerciseMergeServiceTest extends TestCase
{
    use RefreshDatabase;

    private ExerciseMergeService $service;

    private User $admin;

    private User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Instantiate service with required dependency
        $aliasService = app(\App\Services\ExerciseAliasService::class);
        $this->service = new ExerciseMergeService($aliasService);

        // Create admin user
        $adminRole = Role::factory()->create(['name' => 'Admin']);
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach($adminRole);

        // Create regular user
        $this->regularUser = User::factory()->create();
    }

    /** @test */
    public function can_be_merged_returns_false_for_global_exercises()
    {
        $globalExercise = Exercise::factory()->create(['user_id' => null]);

        $this->assertFalse($this->service->canBeMerged($globalExercise));
    }

    /** @test */
    public function can_be_merged_returns_false_when_no_compatible_targets_exist()
    {
        $userExercise = Exercise::factory()->create([
            'user_id' => $this->regularUser->id,
            'log_type' => 'banded',
        ]);

        // No global exercises exist
        $this->assertFalse($this->service->canBeMerged($userExercise));
    }

    /** @test */
    public function can_be_merged_returns_true_when_compatible_targets_exist()
    {
        $userExercise = Exercise::factory()->create([
            'user_id' => $this->regularUser->id,
            'log_type' => 'barbell',
        ]);

        // Create compatible global exercise
        Exercise::factory()->create([
            'user_id' => null,
            'log_type' => 'barbell',
        ]);

        $this->assertTrue($this->service->canBeMerged($userExercise));
    }

    /** @test */
    public function get_potential_targets_excludes_incompatible_bodyweight_exercises()
    {
        $userExercise = Exercise::factory()->create([
            'user_id' => $this->regularUser->id,
            'log_type' => 'bodyweight',
        ]);

        // Create incompatible global exercise (different bodyweight setting)
        Exercise::factory()->create([
            'user_id' => null,
            'log_type' => 'barbell',
        ]);

        $targets = $this->service->getPotentialTargets($userExercise);
        $this->assertCount(0, $targets);
    }

    /** @test */
    public function get_potential_targets_excludes_incompatible_band_types()
    {
        $userExercise = Exercise::factory()->create([
            'user_id' => $this->regularUser->id,
            'log_type' => 'banded',
        ]);

        // Create incompatible global exercise (bodyweight)
        Exercise::factory()->create([
            'user_id' => null,
            'log_type' => 'bodyweight',
        ]);

        $targets = $this->service->getPotentialTargets($userExercise);
        $this->assertCount(0, $targets);
    }

    /** @test */
    public function get_potential_targets_includes_compatible_exercises()
    {
        $userExercise = Exercise::factory()->create([
            'user_id' => $this->regularUser->id,
            'log_type' => 'barbell',
        ]);

        // Create compatible global exercises
        $target1 = Exercise::factory()->create([
            'user_id' => null,
            'title' => 'B Exercise',
            'log_type' => 'barbell',
        ]);

        $target2 = Exercise::factory()->create([
            'user_id' => null,
            // null can merge with any value
            'title' => 'A Exercise',
            'log_type' => 'banded',
        ]);

        $targets = $this->service->getPotentialTargets($userExercise);
        $this->assertCount(2, $targets);

        // Should be ordered by title
        $this->assertEquals('A Exercise', $targets->first()->title);
        $this->assertEquals('B Exercise', $targets->last()->title);
    }

    /** @test */
    public function get_potential_targets_excludes_source_exercise_itself()
    {
        $userExercise = Exercise::factory()->create([
            'user_id' => $this->regularUser->id,
            'log_type' => 'barbell',
        ]);

        $targets = $this->service->getPotentialTargets($userExercise);
        $this->assertFalse($targets->contains($userExercise));
    }

    /** @test */
    public function validate_merge_compatibility_returns_error_for_non_global_target()
    {
        $source = Exercise::factory()->create(['user_id' => $this->regularUser->id]);
        $target = Exercise::factory()->create(['user_id' => $this->regularUser->id]);

        $result = $this->service->validateMergeCompatibility($source, $target);

        $this->assertFalse($result['can_merge']);
        $this->assertContains('Target exercise must be a global exercise.', $result['errors']);
    }

    /** @test */
    public function validate_merge_compatibility_returns_error_for_self_merge()
    {
        $exercise = Exercise::factory()->create(['user_id' => null]);

        $result = $this->service->validateMergeCompatibility($exercise, $exercise);

        $this->assertFalse($result['can_merge']);
        $this->assertContains('Cannot merge exercise into itself.', $result['errors']);
    }

    /** @test */
    public function validate_merge_compatibility_returns_error_for_different_exercise_types()
    {
        $source = Exercise::factory()->create([
            'user_id' => $this->regularUser->id,
            'log_type' => 'bodyweight',
        ]);
        $target = Exercise::factory()->create([
            'user_id' => null,
            'log_type' => 'barbell',
        ]);

        $result = $this->service->validateMergeCompatibility($source, $target);

        $this->assertFalse($result['can_merge']);
        $this->assertContains('Exercises have incompatible types.', $result['errors']);
    }

    /** @test */
    public function validate_merge_compatibility_returns_error_for_incompatible_band_types()
    {
        $source = Exercise::factory()->create([
            'user_id' => $this->regularUser->id,
            'log_type' => 'banded',
        ]);
        $target = Exercise::factory()->create([
            'user_id' => null,
            'log_type' => 'bodyweight',
        ]);

        $result = $this->service->validateMergeCompatibility($source, $target);

        $this->assertFalse($result['can_merge']);
        $this->assertContains('Exercises have incompatible types.', $result['errors']);
    }

    /** @test */
    public function validate_merge_compatibility_returns_warning_for_user_with_global_visibility_disabled()
    {
        $userWithDisabledVisibility = User::factory()->create(['show_global_exercises' => false]);
        $source = Exercise::factory()->create(['user_id' => $userWithDisabledVisibility->id]);
        $target = Exercise::factory()->create(['user_id' => null]);

        $result = $this->service->validateMergeCompatibility($source, $target);

        $this->assertTrue($result['can_merge']);
        $this->assertContains('The owner of this exercise has global exercise visibility disabled. They will lose access to their exercise data after the merge.', $result['warnings']);
    }

    /** @test */
    public function validate_merge_compatibility_returns_success_for_compatible_exercises()
    {
        $source = Exercise::factory()->create([
            'user_id' => $this->regularUser->id,
            'log_type' => 'barbell',
        ]);
        $target = Exercise::factory()->create([
            'user_id' => null,
            'log_type' => 'banded',
        ]);

        $result = $this->service->validateMergeCompatibility($source, $target);

        $this->assertTrue($result['can_merge']);
        $this->assertEmpty($result['errors']);
    }

    /** @test */
    public function merge_exercises_throws_exception_for_incompatible_exercises()
    {
        $source = Exercise::factory()->create([
            'user_id' => $this->regularUser->id,
            'log_type' => 'bodyweight',
        ]);
        $target = Exercise::factory()->create([
            'user_id' => null,
            'log_type' => 'barbell',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Exercises are not compatible for merging');

        $this->service->mergeExercises($source, $target, $this->admin);
    }

    /** @test */
    public function merge_exercises_transfers_lift_logs_successfully()
    {
        $source = Exercise::factory()->create(['user_id' => $this->regularUser->id]);
        $target = Exercise::factory()->create(['user_id' => null]);

        // Create lift logs for source exercise
        $liftLog1 = LiftLog::factory()->create([
            'exercise_id' => $source->id,
            'user_id' => $this->regularUser->id,
            'comments' => 'Original comment',
        ]);
        $liftLog2 = LiftLog::factory()->create([
            'exercise_id' => $source->id,
            'user_id' => $this->regularUser->id,
            'comments' => null,
        ]);

        $this->service->mergeExercises($source, $target, $this->admin);

        // Check lift logs were transferred without modifying comments
        $this->assertDatabaseHas('lift_logs', [
            'id' => $liftLog1->id,
            'exercise_id' => $target->id,
            'comments' => 'Original comment',
        ]);
        $this->assertDatabaseHas('lift_logs', [
            'id' => $liftLog2->id,
            'exercise_id' => $target->id,
            'comments' => null,
        ]);
    }

    /** @test */
    public function merge_exercises_deletes_source_exercise()
    {
        $source = Exercise::factory()->create(['user_id' => $this->regularUser->id]);
        $target = Exercise::factory()->create(['user_id' => null]);

        $this->service->mergeExercises($source, $target, $this->admin);

        // Source exercise should be soft deleted
        $this->assertSoftDeleted($source);

        // Target exercise should remain
        $this->assertDatabaseHas('exercises', ['id' => $target->id, 'deleted_at' => null]);
    }

    /** @test */
    public function merge_exercises_logs_successful_operation()
    {
        $source = Exercise::factory()->create([
            'user_id' => $this->regularUser->id,
            'title' => 'Source Exercise',
        ]);
        $target = Exercise::factory()->create([
            'user_id' => null,
            'title' => 'Target Exercise',
        ]);

        // Create lift logs to verify they're logged
        $liftLog1 = LiftLog::factory()->create(['exercise_id' => $source->id]);
        $liftLog2 = LiftLog::factory()->create(['exercise_id' => $source->id]);

        $result = $this->service->mergeExercises($source, $target, $this->admin);

        $this->assertTrue($result);
        $this->assertSoftDeleted($source);
        $this->assertDatabaseHas('exercises', ['id' => $target->id, 'deleted_at' => null]);

        // Verify database log was created
        $this->assertDatabaseHas('exercise_merge_logs', [
            'source_exercise_id' => $source->id,
            'source_exercise_title' => 'Source Exercise',
            'target_exercise_id' => $target->id,
            'target_exercise_title' => 'Target Exercise',
            'admin_user_id' => $this->admin->id,
            'admin_email' => $this->admin->email,
            'lift_log_count' => 2,
            'alias_created' => true,
        ]);

        // Verify lift log IDs were stored
        $mergeLog = \App\Models\ExerciseMergeLog::latest()->first();
        $this->assertIsArray($mergeLog->lift_log_ids);
        $this->assertCount(2, $mergeLog->lift_log_ids);
        $this->assertContains($liftLog1->id, $mergeLog->lift_log_ids);
        $this->assertContains($liftLog2->id, $mergeLog->lift_log_ids);
    }

    /** @test */
    public function merge_exercises_rolls_back_on_failure()
    {
        $source = Exercise::factory()->create(['user_id' => $this->regularUser->id]);
        $target = Exercise::factory()->create(['user_id' => null]);

        // Create lift log to transfer
        $liftLog = LiftLog::factory()->create(['exercise_id' => $source->id]);

        // Temporarily delete the target exercise to cause a foreign key constraint violation
        // when trying to update lift logs to point to a non-existent exercise
        $targetId = $target->id;
        $target->forceDelete(); // Force delete to actually remove it from database

        $this->expectException(\Exception::class);

        try {
            $this->service->mergeExercises($source, $target, $this->admin);
        } catch (\Exception $e) {
            // Verify rollback occurred - source should still exist and not be soft deleted
            $source->refresh();
            $liftLog->refresh();

            $this->assertNull($source->deleted_at);
            $this->assertEquals($source->id, $liftLog->exercise_id);
            throw $e;
        }
    }

    /** @test */
    public function get_merge_statistics_returns_correct_counts()
    {
        $exercise = Exercise::factory()->create(['user_id' => $this->regularUser->id]);
        $otherUser = User::factory()->create();

        // Create lift logs for different users
        LiftLog::factory()->count(3)->create([
            'exercise_id' => $exercise->id,
            'user_id' => $this->regularUser->id,
        ]);
        LiftLog::factory()->count(2)->create([
            'exercise_id' => $exercise->id,
            'user_id' => $otherUser->id,
        ]);

        $stats = $this->service->getMergeStatistics($exercise);

        $this->assertEquals(5, $stats['lift_logs_count']);
        $this->assertEquals(2, $stats['users_count']);
    }

    /** @test */
    public function merge_creates_canonical_name_alias_when_not_redundant()
    {
        $source = Exercise::create([
            'user_id' => $this->regularUser->id,
            'title' => 'My Dumbbell Press',
            'canonical_name' => 'custom_dumbell_press_slug',
            'log_type' => 'barbell',
        ]);

        $target = Exercise::create([
            'user_id' => null,
            'title' => 'Incline Dumbbell Press',
            'canonical_name' => 'incline_dumbbell_press',
            'log_type' => 'barbell',
        ]);

        $result = $this->service->mergeExercises($source, $target, $this->admin);

        $this->assertTrue($result);

        // Verify a global alias was created with source's canonical_name
        $this->assertDatabaseHas('exercise_aliases', [
            'exercise_id' => $target->id,
            'alias_name' => 'custom_dumbell_press_slug',
            'user_id' => null, // Global alias
        ]);
    }

    /** @test */
    public function merge_does_not_create_redundant_canonical_name_alias()
    {
        $source = Exercise::create([
            'user_id' => $this->regularUser->id,
            'title' => 'Bench Press',
            'canonical_name' => 'bench_press',
            'log_type' => 'barbell',
        ]);

        $target = Exercise::create([
            'user_id' => null,
            'title' => 'Flat Bench Press',
            'canonical_name' => 'flat_bench_press',
            'log_type' => 'barbell',
        ]);

        $result = $this->service->mergeExercises($source, $target, $this->admin);

        $this->assertTrue($result);

        // Verify no global alias was created with 'bench_press'
        $this->assertDatabaseMissing('exercise_aliases', [
            'exercise_id' => $target->id,
            'alias_name' => 'bench_press',
            'user_id' => null,
        ]);
    }
}
