<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shared exercise record calls an exercise's display name `name` (#35), so the column takes that
 * name. A rename only: the data and the unique_exercise_name_per_scope constraint (now on name,
 * user_id) move with the column on both SQLite and MySQL 8.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exercises', function (Blueprint $table) {
            $table->renameColumn('title', 'name');
        });
    }

    public function down(): void
    {
        Schema::table('exercises', function (Blueprint $table) {
            $table->renameColumn('name', 'title');
        });
    }
};
