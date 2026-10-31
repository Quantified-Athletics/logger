<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A program's publish link (Google Sheets first). One row per (gym, program, type);
 * unlink writes a tombstone so other devices learn about it via /changes.
 *
 * Keyed on program_uuid without a foreign key to prism_programs, like the cells table:
 * a destination may arrive before its program row.
 *
 * See docs/plans/prism-program-partial-updates.md §2.3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prism_program_destinations', function (Blueprint $table): void {
            $table->id();
            $table->string('gym_id');
            $table->string('program_uuid');
            $table->string('type', 32);
            $table->string('spreadsheet_id')->nullable();
            $table->unsignedBigInteger('sheet_id')->nullable();
            $table->string('title')->nullable();
            $table->foreignId('linked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('linked_at', 6)->nullable();
            $table->string('device_id', 36)->nullable();
            $table->dateTime('deleted_at', 6)->nullable();
            $table->timestamps(6);

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->unique(['gym_id', 'program_uuid', 'type']);
            $table->index(['gym_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prism_program_destinations');
    }
};
