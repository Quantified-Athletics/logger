<?php

use App\ExerciseLibrary\ExerciseLibraryOpsService;
use App\Models\Exercise;
use App\Models\ExerciseMergeLog;
use App\Models\LiftLog;
use App\Sync\Services\RehydrationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Aligns the standard exercises (`user_id` null) with the canonical library (#42) by applying
 * `database/data/exercise-library/2026-12-canonical.json` through the same service as
 * `php artisan exercise-library:apply`. Every op is checked before anything is written: a file that
 * can't apply throws and writes nothing. A database with no standard exercises (a fresh install, a
 * test database) has nothing to align and is left alone. A second run changes nothing.
 *
 * Like the pull-up merge, it raises the `exercise-merge` rehydration signal so athletes' apps rebuild
 * their local data: once per run, for each user with a (not soft-deleted) lift log this run moved off a
 * merged exercise, in the same transaction as the merges. A run that merges nothing raises nothing.
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
        $ops = $opsService->readOpsFile(database_path(self::OPS_FILE));

        DB::transaction(function () use ($opsService, $ops): void {
            $lastMergeLogIdBefore = (int) ExerciseMergeLog::query()->max('id');

            $opsService->apply($ops);

            $movedLiftLogIds = ExerciseMergeLog::query()
                ->where('id', '>', $lastMergeLogIdBefore)
                ->pluck('lift_log_ids')
                ->flatten()
                ->filter()
                ->unique()
                ->values()
                ->all();

            if ($movedLiftLogIds === []) {
                return;
            }

            $affectedUserIds = LiftLog::query()
                ->whereIn('id', $movedLiftLogIds)
                ->whereNotNull('user_id')
                ->distinct()
                ->orderBy('user_id')
                ->pluck('user_id')
                ->all();

            if ($affectedUserIds === []) {
                return;
            }

            app(RehydrationService::class)->raiseForUsers($affectedUserIds, 'exercise-merge');
        });
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
