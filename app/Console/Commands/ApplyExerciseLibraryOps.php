<?php

namespace App\Console\Commands;

use App\ExerciseLibrary\ExerciseLibraryOpsRefusedException;
use App\ExerciseLibrary\ExerciseLibraryOpsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * The checked bulk path for the exercise library: applies an ops file to the standard exercises.
 * With --check it only proves every op would apply and writes nothing. The root's contracts call
 * `exercise-library:apply --ops=<file> --check`, so keep the name and options.
 */
class ApplyExerciseLibraryOps extends Command
{
    protected $signature = 'exercise-library:apply
        {--ops= : Path to the ops JSON file (absolute, or relative to the app base path)}
        {--check : Prove every op would apply, and write nothing}';

    protected $description = 'Apply an exercise-library ops file to the standard exercises (--check to only prove it applies)';

    public function handle(ExerciseLibraryOpsService $opsService): int
    {
        $path = (string) $this->option('ops');
        if ($path === '') {
            $this->error('Pass the ops file with --ops=<file>.');

            return self::FAILURE;
        }

        if (! str_starts_with($path, '/')) {
            $path = base_path($path);
        }

        $check = (bool) $this->option('check');

        try {
            $report = $opsService->apply($opsService->readOpsFile($path), $check);
        } catch (ExerciseLibraryOpsRefusedException $refused) {
            foreach ($refused->refusals as $refusal) {
                $this->error($refusal);
            }
            $this->error(count($refused->refusals).' refusal(s); nothing was written.');

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('The ops failed to apply, and nothing was written: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%s: %d added, %d updated, %d merged, %d already merged, %d unchanged.',
            $check ? 'Every op would apply (nothing written)' : 'Applied',
            $report['added'],
            $report['updated'],
            $report['merged'],
            $report['already_merged'],
            $report['unchanged'],
        ));

        return self::SUCCESS;
    }
}
