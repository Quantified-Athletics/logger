<?php

use App\Services\ExerciseTypes\LogTypeTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gives every exercise a log type (#33). A cardio exercise without one gets cardio-calories when its
 * sets hold more calorie entries than distance entries, otherwise cardio-distance; any other exercise
 * gets the log type the reverse table names for its exercise_type (barbell for regular). The update
 * runs through the query builder, so the activity log keeps the old (empty) value.
 */
return new class extends Migration
{
    public function up(): void
    {
        $emptyRows = DB::table('exercises')
            ->where(fn ($query) => $query->whereNull('log_type')->orWhere('log_type', ''))
            ->select('id', 'exercise_type')
            ->get();

        foreach ($emptyRows as $row) {
            DB::table('exercises')->where('id', $row->id)->update(['log_type' => $this->logTypeFor($row)]);
        }
    }

    /**
     * The filled rows can't be told apart from rows that had these log types before, and an empty log
     * type is a defect rather than a state worth restoring, so rolling back leaves them filled.
     */
    public function down(): void
    {
        //
    }

    private function logTypeFor(object $row): string
    {
        if ($row->exercise_type === 'cardio') {
            return $this->cardioLogTypeFor($row->id);
        }

        return LogTypeTable::logTypeForExerciseType($row->exercise_type) ?? 'barbell';
    }

    private function cardioLogTypeFor(int $exerciseId): string
    {
        $sets = DB::table('lift_sets')
            ->join('lift_logs', 'lift_logs.id', '=', 'lift_sets.lift_log_id')
            ->where('lift_logs.exercise_id', $exerciseId);

        $calorieSets = (clone $sets)->whereNotNull('lift_sets.calories')->count();
        $distanceSets = (clone $sets)->whereNotNull('lift_sets.distance')->count();

        return $calorieSets > $distanceSets ? 'cardio-calories' : 'cardio-distance';
    }
};
