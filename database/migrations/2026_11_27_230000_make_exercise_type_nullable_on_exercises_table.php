<?php

use App\Services\ExerciseTypes\LogTypeTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The log type decides everything the exercise type used to (#33), so nothing writes
 * exercises.exercise_type any more. The column stays, unread, until a later PR drops it; it becomes
 * nullable so new exercises can be saved without it. No data changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exercises', function (Blueprint $table) {
            $table->string('exercise_type', 50)->nullable()->change();
        });
    }

    /**
     * Fills every empty exercise_type with the strategy the log type table gives the row's log type
     * (regular when it has none), then makes the column NOT NULL again.
     */
    public function down(): void
    {
        $emptyRows = DB::table('exercises')
            ->whereNull('exercise_type')
            ->orWhere('exercise_type', '')
            ->select('id', 'log_type')
            ->get();

        foreach ($emptyRows->groupBy(fn (object $row): string => LogTypeTable::strategyOrFallbackFor($row->log_type)) as $strategy => $rows) {
            DB::table('exercises')->whereIn('id', $rows->pluck('id'))->update(['exercise_type' => $strategy]);
        }

        Schema::table('exercises', function (Blueprint $table) {
            $table->string('exercise_type', 50)->nullable(false)->change();
        });
    }
};
