<?php

namespace Tests\Unit\Services;

use App\Models\Exercise;
use App\Services\WorkoutNameGenerator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkoutNameGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private WorkoutNameGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new WorkoutNameGenerator();
        Carbon::setTestNow('2025-12-06 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function it_falls_back_to_date_based_name()
    {
        $exercise = Exercise::factory()->create(['name' => 'Unknown Exercise']);

        $name = $this->generator->generate($exercise);

        $this->assertEquals('New Workout - Dec 6, 2025', $name);
    }

    /** @test */
    public function it_uses_the_date_fallback_whatever_the_exercise()
    {
        $titles = [
            'Deadlift', 'Squat', 'Running', 'Bench Press', 'Pull Up', 'Leg Press',
            'Shoulder Press', 'Push Up', 'Burpees', 'Box Jumps',
        ];

        foreach ($titles as $title) {
            $exercise = Exercise::factory()->create(['name' => $title]);

            $this->assertEquals('New Workout - Dec 6, 2025', $this->generator->generate($exercise), $title);
        }
    }

    /** @test */
    public function it_uses_the_current_date_in_the_fallback()
    {
        Carbon::setTestNow('2026-01-15 08:00:00');
        $exercise = Exercise::factory()->create(['name' => 'Deadlift']);

        $this->assertEquals('New Workout - Jan 15, 2026', $this->generator->generate($exercise));
    }
}
