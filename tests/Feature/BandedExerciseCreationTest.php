<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BandedExerciseCreationTest extends TestCase
{
    use RefreshDatabase;

    protected $withoutMiddleware = [\App\Http\Middleware\VerifyCsrfToken::class];

    protected User $user;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        
        $adminRole = Role::factory()->create(['name' => 'Admin']);
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach($adminRole);
    }

    /** @test */
    public function a_user_can_create_a_resistance_band_exercise()
    {
        $this->actingAs($this->user);

        $response = $this->post(route('exercises.store'), [
            'name' => 'Banded Pull-ups',
            'description' => 'Pull-ups with a resistance band',
            'log_type' => 'banded',
        ]);

        $response->assertRedirect(route('exercises.index'));
        $this->assertDatabaseHas('exercises', [
            'name' => 'Banded Pull-ups',
            'user_id' => $this->user->id,
            'log_type' => 'banded',
        ]);
    }

    /** @test */
    public function a_user_can_create_an_assistance_band_exercise()
    {
        $this->actingAs($this->user);

        $response = $this->post(route('exercises.store'), [
            'name' => 'Assisted Push-ups',
            'description' => 'Push-ups with an assistance band',
            'log_type' => 'banded',
        ]);

        $response->assertRedirect(route('exercises.index'));
        $this->assertDatabaseHas('exercises', [
            'name' => 'Assisted Push-ups',
            'user_id' => $this->user->id,
            'log_type' => 'banded',
        ]);
    }

    /** @test */
    public function creating_a_banded_exercise_sets_exercise_type_correctly()
    {
        $this->actingAs($this->user);

        $response = $this->post(route('exercises.store'), [
            'name' => 'Banded Dips',
            'description' => 'Dips with a resistance band',
            'log_type' => 'banded',
        ]);

        $response->assertRedirect(route('exercises.index'));
        $this->assertDatabaseHas('exercises', [
            'name' => 'Banded Dips',
            'user_id' => $this->user->id,
            'log_type' => 'banded',
        ]);
    }

    /** @test */
    public function a_user_can_edit_a_banded_exercise()
    {
        $this->actingAs($this->user);
        $exercise = Exercise::factory()->create([
            'user_id' => $this->user->id,
            'log_type' => 'banded',
            'name' => 'Old Banded Exercise',
        ]);

        $response = $this->put(route('exercises.update', $exercise->id), [
            'name' => 'Updated Banded Exercise',
            'description' => 'Updated description',
            'log_type' => 'banded',
        ]);

        $response->assertRedirect(route('exercises.edit', $exercise));
        $this->assertDatabaseHas('exercises', [
            'id' => $exercise->id,
            'name' => 'Updated Banded Exercise',
            'log_type' => 'banded',
        ]);
    }

    /** @test */
    public function editing_a_banded_exercise_maintains_exercise_type()
    {
        $this->actingAs($this->user);
        $exercise = Exercise::factory()->create([
            'user_id' => $this->user->id,
            'log_type' => 'banded',
        ]);

        $response = $this->put(route('exercises.update', $exercise->id), [
            'name' => $exercise->name,
            'description' => $exercise->description,
            'log_type' => 'banded',
        ]);

        $response->assertRedirect(route('exercises.edit', $exercise));
        $this->assertDatabaseHas('exercises', [
            'id' => $exercise->id,
            'log_type' => 'banded',
        ]);
    }

    /** @test */
    public function an_admin_can_create_a_global_banded_exercise()
    {
        $this->actingAs($this->admin);

        // Create user exercise first
        $response = $this->post(route('exercises.store'), [
            'name' => 'Global Banded Exercise',
            'description' => 'A global banded exercise',
            'log_type' => 'banded',
        ]);

        $response->assertRedirect(route('exercises.index'));
        $this->assertDatabaseHas('exercises', [
            'name' => 'Global Banded Exercise',
            'user_id' => $this->admin->id,
            'log_type' => 'banded',
        ]);
        
        // Promote to global
        $exercise = Exercise::where('name', 'Global Banded Exercise')->first();
        $promoteResponse = $this->post(route('exercises.promote', $exercise));
        $promoteResponse->assertRedirect(route('exercises.edit', $exercise));
        
        // Verify it's now global
        $this->assertDatabaseHas('exercises', [
            'name' => 'Global Banded Exercise',
            'user_id' => null,
            'log_type' => 'banded',
        ]);
    }

    /** @test */
    public function an_admin_can_edit_a_global_banded_exercise()
    {
        $this->actingAs($this->admin);
        $exercise = Exercise::factory()->create([
            'user_id' => null,
            'log_type' => 'banded',
            'name' => 'Old Global Banded Exercise',
        ]);

        $response = $this->put(route('exercises.update', $exercise->id), [
            'name' => 'Updated Global Banded Exercise',
            'description' => 'Updated global description',
            'log_type' => 'banded',
        ]);

        $response->assertRedirect(route('exercises.edit', $exercise));
        $this->assertDatabaseHas('exercises', [
            'id' => $exercise->id,
            'name' => 'Updated Global Banded Exercise',
            'log_type' => 'banded',
            'user_id' => null,
        ]);
    }

    /** @test */
    public function exercise_type_validation_works()
    {
        $this->actingAs($this->user);

        $response = $this->post(route('exercises.store'), [
            'name' => 'Invalid Exercise Type',
            'log_type' => 'invalid_type',
        ]);

        $response->assertSessionHasErrors('log_type');
        $this->assertDatabaseMissing('exercises', [
            'name' => 'Invalid Exercise Type',
        ]);
    }
}
