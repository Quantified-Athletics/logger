<?php

namespace App\Services\ExerciseTypes;

/**
 * Reads the log type table in `config/log_types.php`: which exercise type strategy each log type
 * uses, and which log type an old sync client's `exercise_type` stands for.
 */
class LogTypeTable
{
    /**
     * The exercise type strategy name for a log type, or null when the log type is unknown or empty.
     */
    public static function strategyFor(?string $logType): ?string
    {
        if ($logType === null || $logType === '') {
            return null;
        }

        return config('log_types.strategies')[$logType] ?? null;
    }

    /**
     * Every log type the table knows.
     *
     * @return list<string>
     */
    public static function logTypes(): array
    {
        return array_keys(config('log_types.strategies', []));
    }

    /**
     * The log type to store for an old client's exercise type, or null when the exercise type is unknown.
     */
    public static function logTypeForExerciseType(?string $exerciseType): ?string
    {
        if ($exerciseType === null || $exerciseType === '') {
            return null;
        }

        return config('log_types.from_exercise_type')[$exerciseType] ?? null;
    }
}
