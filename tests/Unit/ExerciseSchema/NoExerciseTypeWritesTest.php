<?php

namespace Tests\Unit\ExerciseSchema;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Nothing under app/, config/, resources/, routes/ or the seeders and factories writes the
 * exercises.exercise_type column (#33 Step 3): every writer stores a log_type. The column stays in
 * the database, unwritten, until a later PR drops it.
 */
class NoExerciseTypeWritesTest extends TestCase
{
    /**
     * Patterns that write the column: an attribute array key, a property or array-element assignment,
     * or a setAttribute call.
     *
     * @var list<string>
     */
    private const WRITE_PATTERNS = [
        '/[\'"]exercise_type[\'"]\s*=>/',
        '/->exercise_type\s*=[^=>]/',
        '/\[\s*[\'"]exercise_type[\'"]\s*\]\s*=[^=>]/',
        '/setAttribute\(\s*[\'"]exercise_type[\'"]/',
    ];

    /**
     * Lines that name `exercise_type` without writing the column.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED = [
        // The sync endpoint still accepts an old client's exercise_type; this is its validation rule.
        'app/Sync/Controllers/ExerciseController.php' => ["'exercise_type' => 'nullable|string|max:50'"],
        // The merge log's snapshot keeps its key; it is stored in the merge log, not on the exercise.
        'app/Services/ExerciseMergeService.php' => ["'exercise_type' => \$source->strategyTypeName()"],
    ];

    /**
     * Files that write `exercise_type` into something other than the exercises table.
     *
     * @var list<string>
     */
    private const ALLOWED_FILES = [
        // Exports global exercises to a CSV whose column is still named exercise_type.
        'app/Console/Commands/PersistGlobalExercises.php',
    ];

    #[Test]
    public function no_code_writes_the_exercise_type_column(): void
    {
        $offending = [];

        foreach (['app', 'config', 'resources', 'routes', 'database/seeders', 'database/factories'] as $directory) {
            foreach ($this->phpFiles(base_path($directory)) as $path) {
                $relative = ltrim(str_replace(base_path(), '', $path), '/');
                if (in_array($relative, self::ALLOWED_FILES, true)) {
                    continue;
                }

                foreach (file($path) as $index => $line) {
                    if (! str_contains($line, 'exercise_type') || $this->isComment($line) || $this->isAllowed($relative, $line)) {
                        continue;
                    }

                    foreach (self::WRITE_PATTERNS as $pattern) {
                        if (preg_match($pattern, $line)) {
                            $offending[] = "{$relative}:".($index + 1).': '.trim($line);
                            break;
                        }
                    }
                }
            }
        }

        $this->assertSame([], $offending, "These lines write exercise_type:\n".implode("\n", $offending));
    }

    private function isAllowed(string $relative, string $line): bool
    {
        foreach (self::ALLOWED[$relative] ?? [] as $allowed) {
            if (str_contains($line, $allowed)) {
                return true;
            }
        }

        return false;
    }

    private function isComment(string $line): bool
    {
        $trimmed = ltrim($line);

        return str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '{{--');
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $directory): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
