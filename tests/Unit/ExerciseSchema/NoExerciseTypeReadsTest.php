<?php

namespace Tests\Unit\ExerciseSchema;

use App\Models\Exercise;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Nothing under app/, config/, resources/ or routes/ reads the exercises.exercise_type column: the
 * log type decides everything. Writes are Step 3's (#33) and are not checked here. Reading an old
 * sync client's `exercise_type` request field, and the reverse table, are allowed.
 */
class NoExerciseTypeReadsTest extends TestCase
{
    /**
     * Patterns that read the column: a property read that isn't an assignment, a query on it,
     * a select of it, or the form's old() lookup.
     *
     * @var list<string>
     */
    private const READ_PATTERNS = [
        '/->exercise_type\b(?!\s*=[^=>])/',
        '/(where\w*|orWhere\w*|orderBy|groupBy|pluck|select|value)\(\s*[\'"](?:\w+\.)?exercise_type[\'"]/',
        '/[\'"]exercises\.exercise_type[\'"]/',
        '/old\(\s*[\'"]exercise_type[\'"]/',
        '/isDirty\(\s*[\'"]exercise_type[\'"]/',
        '/getAttribute\(\s*[\'"]exercise_type[\'"]/',
    ];

    #[Test]
    public function no_code_reads_the_exercise_type_column(): void
    {
        $allowed = $this->allowedLines();
        $offending = [];

        foreach (['app', 'config', 'resources', 'routes'] as $directory) {
            foreach ($this->phpFiles(base_path($directory)) as $path) {
                foreach (file($path) as $index => $line) {
                    $lineNumber = $index + 1;
                    $relative = ltrim(str_replace(base_path(), '', $path), '/');

                    if (! str_contains($line, 'exercise_type') || $this->isComment($line)) {
                        continue;
                    }
                    if (in_array($lineNumber, $allowed[$relative] ?? [], true)) {
                        continue;
                    }

                    foreach (self::READ_PATTERNS as $pattern) {
                        if (preg_match($pattern, $line)) {
                            $offending[] = "{$relative}:{$lineNumber}: ".trim($line);
                            break;
                        }
                    }
                }
            }
        }

        $this->assertSame([], $offending, "These lines read exercise_type:\n".implode("\n", $offending));
    }

    /**
     * Until Step 3 moves the writers, the model fills a missing log_type from a written exercise_type
     * through the reverse table. That bridge is the one allowed read; Step 3 removes it.
     *
     * @return array<string, list<int>>
     */
    private function allowedLines(): array
    {
        $bridge = new ReflectionMethod(Exercise::class, 'fillLogTypeFromWrittenExerciseType');

        return [
            'app/Models/Exercise.php' => range($bridge->getStartLine(), $bridge->getEndLine()),
        ];
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
