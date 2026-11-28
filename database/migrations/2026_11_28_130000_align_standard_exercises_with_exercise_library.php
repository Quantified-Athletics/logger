<?php

use App\ExerciseLibrary\ExerciseLibraryOpsService;
use App\Models\Exercise;
use Illuminate\Database\Migrations\Migration;

/**
 * Aligns the standard exercises (`user_id` null) with the canonical library (#42) by applying
 * `database/data/exercise-library/2026-12-canonical.json` through the same service as
 * `php artisan exercise-library:apply`. Every op is checked before anything is written: a file that
 * can't apply throws and writes nothing. A database with no standard exercises (a fresh install, a
 * test database) has nothing to align and is left alone. A second run changes nothing.
 */
return new class extends Migration
{
    private const OPS_FILE = 'data/exercise-library/2026-12-canonical.json';

    public function up(): void
    {
        if (! Exercise::query()->whereNull('user_id')->exists()) {
            return;
        }

        $opsService = app(ExerciseLibraryOpsService::class);
        $opsService->apply($opsService->readOpsFile(database_path(self::OPS_FILE)));
    }

    /**
     * The activity log keeps the old value of every change and each merge has its `ExerciseMergeLog`
     * row, but undoing 327 updates and merges blind would risk more than it restores, so rolling back
     * leaves the aligned rows as they are.
     */
    public function down(): void
    {
        //
    }
};
