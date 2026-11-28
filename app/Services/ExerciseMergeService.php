<?php

namespace App\Services;

use App\Models\Exercise;
use App\Models\ExerciseAlias;
use App\Models\ExerciseMatchingAlias;
use App\Models\ExerciseMergeLog;
use App\Models\LiftLog;
use App\Models\PersonalRecord;
use App\Models\PRDetectionLog;
use App\Models\User;
use App\Models\WorkoutExercise;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExerciseMergeService
{
    protected ExerciseAliasService $aliasService;

    public function __construct(ExerciseAliasService $aliasService)
    {
        $this->aliasService = $aliasService;
    }

    /**
     * Determine if an exercise can be merged by an admin
     */
    public function canBeMerged(Exercise $sourceExercise): bool
    {
        // Only user exercises can be merged (not global exercises)
        if ($sourceExercise->isGlobal()) {
            return false;
        }

        // Must have at least one potential target
        return $this->getPotentialTargets($sourceExercise)->isNotEmpty();
    }

    /**
     * Get potential global target exercises for merging
     */
    public function getPotentialTargets(Exercise $sourceExercise): Collection
    {
        return Exercise::onlyGlobal()
            ->where('id', '!=', $sourceExercise->id)
            ->get()
            ->filter(function ($target) use ($sourceExercise) {
                return $sourceExercise->isCompatibleForMerge($target);
            })
            ->sortBy('name')
            ->values();
    }

    /**
     * Validate merge compatibility between source and target exercises
     */
    public function validateMergeCompatibility(Exercise $source, Exercise $target): array
    {
        $errors = [];
        $warnings = [];

        // Target must be global
        if (! $target->isGlobal()) {
            $errors[] = 'Target exercise must be a global exercise.';
        }

        // Cannot merge into self
        if ($source->id === $target->id) {
            $errors[] = 'Cannot merge exercise into itself.';
        }

        // Check exercise type compatibility using the model's method
        if (! $source->isCompatibleForMerge($target)) {
            $errors[] = 'Exercises have incompatible types.';
        }

        // Check if source exercise owner has global visibility disabled
        if ($source->user && ! $source->user->shouldShowGlobalExercises()) {
            $warnings[] = 'The owner of this exercise has global exercise visibility disabled. They will lose access to their exercise data after the merge.';
        }

        return [
            'errors' => $errors,
            'warnings' => $warnings,
            'can_merge' => empty($errors),
        ];
    }

    /**
     * Perform the exercise merge operation
     */
    public function mergeExercises(Exercise $source, Exercise $target, User $admin, bool $createAlias = true): bool
    {
        // Validate compatibility first
        $validation = $this->validateMergeCompatibility($source, $target);
        if (! $validation['can_merge']) {
            throw new \InvalidArgumentException('Exercises are not compatible for merging: '.implode(', ', $validation['errors']));
        }

        // Collect lift log IDs before transfer
        $liftLogIds = LiftLog::where('exercise_id', $source->id)->pluck('id')->toArray();

        try {
            DB::beginTransaction();

            // Transfer lift logs
            $this->transferLiftLogs($source, $target);

            // Create alias for the source exercise owner if requested
            $aliasCreated = $this->createAliasForOwner($source, $target, $createAlias);

            // Create global canonical name alias if not redundant
            $this->createCanonicalNameAlias($source, $target);

            // Delete the source exercise
            $source->delete();

            // Create database log entry
            ExerciseMergeLog::create([
                'source_exercise_id' => $source->id,
                'source_exercise_title' => $source->name,
                'target_exercise_id' => $target->id,
                'target_exercise_title' => $target->name,
                'admin_user_id' => $admin->id,
                'admin_email' => $admin->email,
                'lift_log_ids' => $liftLogIds,
                'lift_log_count' => count($liftLogIds),
                'alias_created' => $aliasCreated,
            ]);

            // Also log to Laravel log for immediate visibility
            Log::info('Exercise merge completed', [
                'source_exercise_id' => $source->id,
                'source_exercise_title' => $source->name,
                'target_exercise_id' => $target->id,
                'target_exercise_title' => $target->name,
                'admin_user_id' => $admin->id,
                'admin_email' => $admin->email,
                'lift_log_count' => count($liftLogIds),
                'alias_created' => $aliasCreated,
            ]);

            DB::commit();

            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Exercise merge failed', [
                'source_exercise_id' => $source->id,
                'target_exercise_id' => $target->id,
                'admin_user_id' => $admin->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Fold one standard exercise into another for the exercise library's ops (#42). The admin screen's
     * `mergeExercises` only folds a user's exercise into a standard one and needs an admin, so the
     * library's standard-to-standard merges come here instead; that path is unchanged.
     *
     * Every lift log (soft-deleted ones too), personal record, PR detection log, workout entry and
     * matching alias moves to the target. A user alias moves unless that user already has one on the
     * target; where the user had deleted one there, that row still holds the one slot per user and
     * exercise, so it is restored under the moving alias's name instead. A standard alias moves unless the target already has that name, or the caller retires
     * the source's standard aliases because the same ops file sets the target's. The source is then
     * soft-deleted and an `ExerciseMergeLog` row records the merge with its reason.
     */
    public function mergeStandardExercise(Exercise $source, Exercise $target, string $reason, bool $retireStandardAliases): ExerciseMergeLog
    {
        if (! $source->isGlobal() || ! $target->isGlobal()) {
            throw new \InvalidArgumentException('Only a standard exercise can be folded into a standard exercise.');
        }

        if ($source->id === $target->id) {
            throw new \InvalidArgumentException('Cannot merge exercise into itself.');
        }

        return DB::transaction(function () use ($source, $target, $reason, $retireStandardAliases): ExerciseMergeLog {
            $liftLogIds = LiftLog::withTrashed()->where('exercise_id', $source->id)->orderBy('id')->pluck('id')->all();

            LiftLog::withTrashed()->where('exercise_id', $source->id)->update(['exercise_id' => $target->id]);
            PersonalRecord::withTrashed()->where('exercise_id', $source->id)->update(['exercise_id' => $target->id]);
            PRDetectionLog::query()->where('exercise_id', $source->id)->update(['exercise_id' => $target->id]);
            WorkoutExercise::query()->where('exercise_id', $source->id)->update(['exercise_id' => $target->id]);

            $targetMatchingAliases = ExerciseMatchingAlias::query()->where('exercise_id', $target->id)->pluck('alias')->all();
            ExerciseMatchingAlias::query()
                ->where('exercise_id', $source->id)
                ->whereNotIn('alias', $targetMatchingAliases)
                ->update(['exercise_id' => $target->id]);

            $movedAliasIds = [];
            $retiredAliasIds = [];
            $revivedAliases = [];
            foreach (ExerciseAlias::query()->where('exercise_id', $source->id)->orderBy('id')->get() as $alias) {
                $targetAlready = ExerciseAlias::query()->where('exercise_id', $target->id);
                $targetAlready = $alias->user_id === null
                    ? $targetAlready->whereNull('user_id')->where('alias_name', $alias->alias_name)
                    : $targetAlready->where('user_id', $alias->user_id);

                if (($alias->user_id === null && $retireStandardAliases) || $targetAlready->exists()) {
                    $alias->delete();
                    $retiredAliasIds[] = $alias->id;

                    continue;
                }

                $deletedOnTarget = $alias->user_id === null ? null : ExerciseAlias::onlyTrashed()
                    ->where('exercise_id', $target->id)
                    ->where('user_id', $alias->user_id)
                    ->first();

                if ($deletedOnTarget !== null) {
                    $revivedAliases[] = ['id' => $deletedOnTarget->id, 'previous_alias_name' => $deletedOnTarget->alias_name];
                    $deletedOnTarget->alias_name = $alias->alias_name;
                    $deletedOnTarget->restore();
                    $alias->delete();
                    $retiredAliasIds[] = $alias->id;

                    continue;
                }

                $alias->update(['exercise_id' => $target->id]);
                $movedAliasIds[] = $alias->id;
            }

            $source->delete();

            return ExerciseMergeLog::create([
                'source_exercise_id' => $source->id,
                'source_exercise_title' => $source->name,
                'target_exercise_id' => $target->id,
                'target_exercise_title' => $target->name,
                'admin_user_id' => null,
                'admin_email' => null,
                'lift_log_ids' => $liftLogIds,
                'lift_log_count' => count($liftLogIds),
                'alias_created' => false,
                'snapshot' => [
                    'reason' => $reason,
                    'source' => [
                        'id' => $source->id,
                        'canonical_name' => $source->canonical_name,
                        'name' => $source->name,
                        'log_type' => $source->log_type,
                    ],
                    'target' => [
                        'id' => $target->id,
                        'canonical_name' => $target->canonical_name,
                        'name' => $target->name,
                        'log_type' => $target->log_type,
                    ],
                    'aliases_moved' => $movedAliasIds,
                    'aliases_retired' => $retiredAliasIds,
                    'aliases_revived' => $revivedAliases,
                ],
            ]);
        });
    }

    /**
     * Transfer all lift logs from source to target exercise
     */
    private function transferLiftLogs(Exercise $source, Exercise $target): void
    {
        $liftLogs = LiftLog::where('exercise_id', $source->id)->get();

        foreach ($liftLogs as $liftLog) {
            // Update exercise_id to target without modifying comments
            $liftLog->update(['exercise_id' => $target->id]);
        }
    }

    /**
     * Create alias for the source exercise owner if requested
     *
     * @return bool Whether an alias was created
     */
    private function createAliasForOwner(Exercise $source, Exercise $target, bool $createAlias): bool
    {
        // Don't create alias if not requested
        if (! $createAlias) {
            return false;
        }

        // Don't create alias if source has no owner (global exercise)
        if (! $source->user) {
            return false;
        }

        try {
            $this->aliasService->createAlias(
                $source->user,
                $target,
                $source->name
            );

            Log::info('Exercise alias created during merge', [
                'user_id' => $source->user->id,
                'user_email' => $source->user->email,
                'exercise_id' => $target->id,
                'exercise_title' => $target->name,
                'alias_name' => $source->name,
                'created_via' => 'merge_operation',
            ]);

            return true;
        } catch (QueryException $e) {
            // Handle duplicate alias errors gracefully
            if ($e->getCode() === '23000') {
                Log::warning('Duplicate alias during merge - alias already exists', [
                    'user_id' => $source->user->id,
                    'exercise_id' => $target->id,
                    'alias_name' => $source->name,
                ]);

                // Continue with merge - alias already exists
                return false;
            }

            // For other database errors, log and continue
            Log::error('Failed to create alias during merge', [
                'user_id' => $source->user->id,
                'exercise_id' => $target->id,
                'alias_name' => $source->name,
                'error' => $e->getMessage(),
            ]);

            // Don't fail the merge due to alias creation failure
            return false;
        } catch (\Exception $e) {
            // Catch any other exceptions and log them
            Log::error('Unexpected error creating alias during merge', [
                'user_id' => $source->user->id,
                'exercise_id' => $target->id,
                'alias_name' => $source->name,
                'error' => $e->getMessage(),
            ]);

            // Don't fail the merge due to alias creation failure
            return false;
        }
    }

    /**
     * Create a global alias for the target exercise using the source exercise's canonical name if not redundant.
     */
    private function createCanonicalNameAlias(Exercise $source, Exercise $target): void
    {
        $canonical = $source->canonical_name;
        $titleSnake = \Illuminate\Support\Str::snake($source->name);
        $titleSlug = \Illuminate\Support\Str::slug($source->name, '_');

        // Skip if canonical_name is identical to snake_case or slug of title
        if ($canonical === $titleSnake || $canonical === $titleSlug) {
            return;
        }

        // Check if alias already exists for the target exercise
        $exists = \App\Models\ExerciseAlias::query()
            ->where('exercise_id', $target->id)
            ->where('alias_name', $canonical)
            ->exists();

        if ($exists) {
            return;
        }

        try {
            \App\Models\ExerciseAlias::create([
                'user_id' => null, // Global alias
                'exercise_id' => $target->id,
                'alias_name' => $canonical,
            ]);

            Log::info('Global canonical name alias created during merge', [
                'target_exercise_id' => $target->id,
                'alias_name' => $canonical,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Check if it's a duplicate entry error
            if ($e->getCode() === '23000') {
                Log::warning('Duplicate canonical alias during merge - alias already exists', [
                    'exercise_id' => $target->id,
                    'alias_name' => $canonical,
                ]);
            } else {
                throw $e;
            }
        }
    }

    /**
     * Get merge statistics for display purposes
     */
    public function getMergeStatistics(Exercise $exercise): array
    {
        return [
            'lift_logs_count' => $exercise->liftLogs()->count(),
            'users_count' => $exercise->liftLogs()->distinct('user_id')->count('user_id'),
        ];
    }

    /**
     * Perform a map-driven exercise merge across global exercises.
     *
     * @param array{target: string, title: string, sources: array<int, string>} $merge
     * @return array<int, int> Affected user IDs
     */
    public function mergeByMap(array $merge): array
    {
        $targetIdentifier = $merge['target'];
        $targetTitle = $merge['title'];
        $sourceIdentifiers = $merge['sources'];

        // Resolve target global exercise
        $target = $this->resolveGlobalExercise($targetIdentifier, $targetTitle);
        if (! $target) {
            throw new \RuntimeException("Target exercise [{$targetIdentifier} / {$targetTitle}] not found.");
        }

        // Resolve source global exercises
        $sources = [];
        foreach ($sourceIdentifiers as $sourceId) {
            $source = $this->resolveGlobalExercise($sourceId, $sourceId);
            if ($source) {
                if ($source->id === $target->id) {
                    continue;
                }
                $sources[$source->id] = $source;
            }
        }

        if (empty($sources)) {
            // Idempotent: all sources already merged / deleted / absent
            return [];
        }

        $affectedUserIds = [];

        DB::transaction(function () use ($merge, $target, $sources, &$affectedUserIds) {
            $targetOriginalCanonical = $target->canonical_name;
            $targetOriginalTitle = $target->name;

            foreach ($sources as $source) {
                // Collect lift log IDs and affected user IDs
                $liftLogs = LiftLog::where('exercise_id', $source->id)->get();
                $liftLogIds = $liftLogs->pluck('id')->all();
                foreach ($liftLogs as $log) {
                    if ($log->user_id) {
                        $affectedUserIds[$log->user_id] = $log->user_id;
                    }
                }

                // Repoint lift logs
                LiftLog::where('exercise_id', $source->id)->update(['exercise_id' => $target->id]);

                // Create global alias for source canonical_name and title on target
                $aliasesCreated = [];
                $aliasesToCreate = array_filter(array_unique([$source->canonical_name, $source->name]));
                foreach ($aliasesToCreate as $aliasName) {
                    $exists = \App\Models\ExerciseAlias::query()
                        ->where('exercise_id', $target->id)
                        ->where('alias_name', $aliasName)
                        ->whereNull('user_id')
                        ->exists();

                    if (! $exists) {
                        $aliasModel = \App\Models\ExerciseAlias::create([
                            'user_id' => null,
                            'exercise_id' => $target->id,
                            'alias_name' => $aliasName,
                        ]);
                        $aliasesCreated[] = $aliasModel->id;
                    }
                }

                // Create audit log
                ExerciseMergeLog::create([
                    'source_exercise_id' => $source->id,
                    'source_exercise_title' => $source->name,
                    'target_exercise_id' => $target->id,
                    'target_exercise_title' => $target->name,
                    'admin_user_id' => null,
                    'admin_email' => null,
                    'lift_log_ids' => $liftLogIds,
                    'lift_log_count' => count($liftLogIds),
                    'alias_created' => ! empty($aliasesCreated),
                    'snapshot' => [
                        'source' => [
                            'id' => $source->id,
                            'canonical_name' => $source->canonical_name,
                            'name' => $source->name,
                            'log_type' => $source->log_type,
                            'exercise_type' => $source->strategyTypeName(),
                        ],
                        'target_original' => [
                            'id' => $target->id,
                            'canonical_name' => $targetOriginalCanonical,
                            'name' => $targetOriginalTitle,
                        ],
                        'aliases_created' => $aliasesCreated,
                    ],
                ]);

                // Soft-delete source exercise
                $source->delete();
            }

            // Set target canonical name and title per map (naming authority)
            $target->update([
                'canonical_name' => $merge['target'],
                'name' => $merge['title'],
            ]);
        });

        return array_values($affectedUserIds);
    }

    /**
     * Resolve a global exercise by canonical_name, then by title (case-insensitive).
     */
    private function resolveGlobalExercise(string $identifier, string $fallbackTitle): ?Exercise
    {
        $query = Exercise::whereNull('user_id')->whereNull('deleted_at');

        $matches = (clone $query)->where('canonical_name', $identifier)->get();

        if ($matches->count() > 1) {
            throw new \RuntimeException("Ambiguous resolution for global exercise canonical_name [{$identifier}]: multiple rows found.");
        }

        if ($matches->count() === 1) {
            return $matches->first();
        }

        $titleMatches = (clone $query)->whereRaw('LOWER(name) = ?', [mb_strtolower($fallbackTitle)])->get();

        if ($titleMatches->count() > 1) {
            throw new \RuntimeException("Ambiguous resolution for global exercise title [{$fallbackTitle}]: multiple rows found.");
        }

        return $titleMatches->first();
    }
}
