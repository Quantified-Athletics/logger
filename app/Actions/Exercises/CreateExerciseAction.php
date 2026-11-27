<?php

namespace App\Actions\Exercises;

use App\Models\Exercise;
use App\Models\User;
use App\Services\ExerciseTypes\ExerciseTypeFactory;
use App\Services\ExerciseTypes\LogTypeTable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class CreateExerciseAction
{
    public function execute(Request $request, User $user): Exercise
    {
        // Validate the request
        $validated = $this->validateRequest($request, $user);
        
        // Check for name conflicts
        $this->validateExerciseName($validated['name'], $user);

        $logType = $validated['log_type'];

        // Create a temporary exercise to determine the strategy
        $tempExercise = new Exercise([
            'log_type' => $logType,
        ]);

        // Use exercise type strategy to process exercise data
        $exerciseTypeStrategy = ExerciseTypeFactory::create($tempExercise);
        $processedData = $exerciseTypeStrategy->processExerciseData($validated);

        $exercise = new Exercise([
            'name' => $processedData['name'],
            'description' => $processedData['description'] ?? null,
            'log_type' => $logType,
            'user_id' => $user->id, // All new exercises are user exercises
            'show_in_feed' => $validated['show_in_feed'] ?? false,
        ]);

        $exercise->save();
        
        return $exercise;
    }
    
    private function validateRequest(Request $request, User $user): array
    {
        $rules = [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'log_type' => ['required', Rule::in(LogTypeTable::logTypes())],
            'show_in_feed' => 'nullable|boolean',
        ];

        return $request->validate($rules, [], ['name' => 'title']);
    }
    
    private function validateExerciseName(string $name, User $user): void
    {
        // All new exercises are user exercises, so check for conflicts with user's exercises and global exercises
        $userId = $user->id;
        $conflicts = Exercise::where('name', $name)
            ->where(function ($q) use ($userId) {
                $q->whereNull('user_id')
                  ->orWhere('user_id', $userId);
            })
            ->exists();

        if ($conflicts) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'name' => 'An exercise with this name already exists.'
            ]);
        }
    }
}