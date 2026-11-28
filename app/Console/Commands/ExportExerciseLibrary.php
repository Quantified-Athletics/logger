<?php

namespace App\Console\Commands;

use App\ExerciseLibrary\ExerciseLibraryExportService;
use Illuminate\Console\Command;

/**
 * Prints the standard exercises as a JSON array on stdout, and nothing else, for the root's
 * contracts. Keep the name and the output shape.
 */
class ExportExerciseLibrary extends Command
{
    protected $signature = 'exercise-library:export';

    protected $description = 'Print the standard exercises as a JSON array (the exercise library export)';

    public function handle(ExerciseLibraryExportService $exportService): int
    {
        $this->line(json_encode(
            $exportService->standardExercises(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
