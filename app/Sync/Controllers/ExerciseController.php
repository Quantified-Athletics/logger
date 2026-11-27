<?php

namespace App\Sync\Controllers;

use App\Models\Exercise;
use App\Services\ExerciseTypes\LogTypeTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ExerciseController
{
    /**
     * Create a user-scoped exercise from the Athlete app.
     *
     * POST /api/sync/exercises
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'canonical_name' => 'nullable|string|max:255',
            'exercise_type' => 'nullable|string|max:50',
            'log_type' => 'nullable|string|max:50',
            'show_in_feed' => 'nullable|boolean',
        ]);

        $user = $request->user();
        $logType = $this->logTypeFor($request);
        $title = $request->input('title');
        $canonicalName = $request->input('canonical_name') ?: Str::snake(Str::lower($title));

        // Check if user already has an exercise with this canonical name
        $existing = Exercise::withTrashed()
            ->where('user_id', $user->id)
            ->where('canonical_name', $canonicalName)
            ->first();

        if ($existing) {
            // If soft-deleted, restore it
            if ($existing->trashed()) {
                $existing->restore();
            }
            // Update fields
            $existing->update([
                'title' => $title,
                'log_type' => $this->keepsStrategy($request, $existing, $logType) ? $existing->log_type : $logType,
                'show_in_feed' => $request->input('show_in_feed', true),
            ]);

            return response()->json([
                'status' => 'ok',
                'exercise_id' => $existing->id,
                'canonical_name' => $existing->canonical_name,
            ]);
        }

        // Create new user-scoped exercise
        $exercise = Exercise::create([
            'title' => $title,
            'canonical_name' => $canonicalName,
            'user_id' => $user->id,
            'log_type' => $logType,
            'show_in_feed' => $request->input('show_in_feed', true),
        ]);

        return response()->json([
            'status' => 'ok',
            'exercise_id' => $exercise->id,
            'canonical_name' => $exercise->canonical_name,
        ], 201);
    }

    /**
     * The log type to store: the client's own, or, for an old client that sends only an exercise type,
     * the one the reverse table names for it (regular when it sends neither, as before).
     */
    private function logTypeFor(Request $request): string
    {
        if ($request->filled('log_type')) {
            return $request->input('log_type');
        }

        return LogTypeTable::logTypeForExerciseType($request->input('exercise_type', 'regular'))
            ?? LogTypeTable::DEFAULT_LOG_TYPE;
    }

    /**
     * An old client's update that sends no log type keeps the exercise's own log type when the
     * exercise type it sends uses the same strategy, so a kettlebell exercise isn't turned into barbell.
     */
    private function keepsStrategy(Request $request, Exercise $existing, string $derivedLogType): bool
    {
        return ! $request->filled('log_type')
            && ! empty($existing->log_type)
            && LogTypeTable::strategyFor($existing->log_type) === LogTypeTable::strategyFor($derivedLogType);
    }
}
