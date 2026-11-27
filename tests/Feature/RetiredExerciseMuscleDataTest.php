<?php

namespace Tests\Feature;

use App\Models\Exercise;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Logger's own copy of exercise muscle and movement data is retired (#31, step 3): no code reads or writes it.
 *
 * The retired word is built from two parts so this file passes the same scan it runs over `tests/`.
 */
class RetiredExerciseMuscleDataTest extends TestCase
{
    use RefreshDatabase;

    private const RETIRED_WORD = 'intel'.'ligence';

    /**
     * @var array<int, string>
     */
    private const SCANNED_DIRECTORIES = [
        'app',
        'config',
        'routes',
        'resources',
        'database/seeders',
        'database/factories',
        'tests',
    ];

    #[Test]
    public function no_file_outside_the_migrations_names_the_retired_data(): void
    {
        $offenders = [];

        foreach (self::SCANNED_DIRECTORIES as $directory) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(base_path($directory), RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $relativePath = substr($file->getPathname(), strlen(base_path()) + 1);

                if (stripos($relativePath, self::RETIRED_WORD) !== false
                    || stripos(file_get_contents($file->getPathname()), self::RETIRED_WORD) !== false) {
                    $offenders[] = $relativePath;
                }
            }
        }

        $this->assertSame([], $offenders, 'These files still name the retired data');
    }

    #[Test]
    public function no_artisan_command_names_the_retired_data(): void
    {
        $commands = array_filter(
            array_keys(Artisan::all()),
            fn (string $name): bool => stripos($name, self::RETIRED_WORD) !== false
        );

        $this->assertSame([], array_values($commands));
    }

    #[Test]
    public function the_model_and_its_relation_are_gone(): void
    {
        $this->assertFalse(class_exists('App\\Models\\Exercise'.ucfirst(self::RETIRED_WORD)));
        $this->assertFalse(method_exists(Exercise::class, self::RETIRED_WORD));
    }

    #[Test]
    public function seeding_a_fresh_database_passes_and_writes_no_retired_rows(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertGreaterThan(0, Exercise::query()->count());
        $this->assertDatabaseCount('exercise_'.self::RETIRED_WORD, 0);
    }

    #[Test]
    public function deleting_an_exercise_still_soft_deletes_it(): void
    {
        $exercise = Exercise::factory()->create();

        $exercise->delete();

        $this->assertSoftDeleted($exercise);
    }
}
