<?php

namespace App\Services;

use App\Models\Exercise;
use App\Models\Workout;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class WorkoutNameGenerator
{
    /**
     * Generate a workout label from the exercises in the workout
     */
    public function generateFromWorkout(Workout $workout, bool $includeCount = true): string
    {
        $exercises = $workout->exercises()->with('exercise')->get();
        
        if ($exercises->count() === 0) {
            return 'Empty Workout';
        }
        
        // For 1-2 exercises, just list them
        if ($exercises->count() <= 2) {
            $names = $exercises->pluck('exercise.title')->filter()->toArray();
            if (count($names) === 0) {
                if ($includeCount) {
                    return 'Workout • ' . $exercises->count() . ' exercises';
                }
                return 'Workout';
            }
            return implode(' & ', $names);
        }
        
        // For 3+ exercises, use the fallback label
        $label = $this->fallbackLabel($exercises);
        if ($includeCount) {
            return $label . ' • ' . $exercises->count() . ' exercises';
        }
        return $label;
    }
    
    /**
     * Fallback label inferred from exercise names
     */
    private function fallbackLabel(Collection $exercises): string
    {
        // Try to infer from exercise names
        $names = $exercises->pluck('exercise.title')->map(fn($n) => strtolower($n))->toArray();
        $allNames = implode(' ', $names);
        
        // Simple keyword matching
        if (str_contains($allNames, 'squat') || str_contains($allNames, 'leg')) {
            return 'Leg Day';
        }
        
        if (str_contains($allNames, 'bench') || str_contains($allNames, 'press')) {
            return 'Upper Body';
        }
        
        if (str_contains($allNames, 'deadlift') || str_contains($allNames, 'hinge')) {
            return 'Lower Body';
        }
        
        return 'Mixed Workout';
    }
    
    /**
     * Generate a date-based workout name for a workout started from an exercise (legacy method)
     */
    public function generate(Exercise $exercise): string
    {
        return 'New Workout - ' . Carbon::now()->format('M j, Y');
    }
}
