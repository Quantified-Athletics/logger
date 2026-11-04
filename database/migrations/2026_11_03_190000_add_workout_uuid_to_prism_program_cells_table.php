<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds the workout's stable identity (Prism `workout.uuid`) to its cell, so it round-trips across
     * devices and Prism comments can key on it. Nullable: cells pushed by clients from before workout
     * identity keep working, and a push without a uuid never clears a stored one. The unique key
     * (gym_id, program_uuid, cell_id) is unchanged: position stays the cell's key, uuid is an attribute.
     */
    public function up(): void
    {
        Schema::table('prism_program_cells', function (Blueprint $table) {
            $table->string('workout_uuid', 36)->nullable()->after('cell_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prism_program_cells', function (Blueprint $table) {
            $table->dropColumn('workout_uuid');
        });
    }
};
