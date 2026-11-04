<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coach comments on a Prism workout (prism#443). One row per comment, keyed by the comment's own uuid;
 * it belongs to a workout by the workout's stable uuid, not its position. A delete writes a tombstone
 * (deleted_at) so other devices learn about it via /changes, and an undo can restore it.
 *
 * Keyed on program_uuid without a foreign key to prism_programs, like cells and destinations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prism_program_comments', function (Blueprint $table): void {
            $table->id();
            $table->string('gym_id');
            $table->string('program_uuid');
            $table->string('uuid', 36);
            $table->string('workout_uuid', 36);
            $table->text('body');
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('device_id', 36)->nullable();
            $table->dateTime('deleted_at', 6)->nullable();
            $table->timestamps(6);

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->unique(['gym_id', 'uuid']);
            $table->index(['gym_id', 'updated_at']);
            $table->index(['gym_id', 'program_uuid', 'workout_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prism_program_comments');
    }
};
