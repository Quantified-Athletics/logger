<?php

namespace Tests\Feature\Sync\Prism;

use App\Sync\Models\Gym;
use Database\Seeders\GymSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MauritsGymTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrations_create_the_maurits_gym(): void
    {
        $this->assertSame('Maurits PT', Gym::find('maurits')?->name);
    }

    public function test_seeder_after_migration_keeps_one_maurits_gym(): void
    {
        $this->seed(GymSeeder::class);

        $this->assertSame(1, Gym::where('id', 'maurits')->count());
        $this->assertSame('Maurits PT', Gym::find('maurits')->name);
    }
}
