<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A "publish to Athlete" request for a Prism program. Append-only: a new publication of the
 * same program is a new row, and the API never edits or deletes one. Athlete's GitHub Action
 * pulls rows with `id > after`, so the primary key is the only read index needed.
 *
 * `raw` is the program file as JSON text (not a MySQL JSON column, which would reorder keys).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prism_athlete_publications', function (Blueprint $table): void {
            $table->id();
            $table->string('gym_id');
            $table->string('program_uuid');
            $table->string('track', 8);
            $table->date('start_date');
            $table->longText('raw');
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('published_at', 6);
            $table->timestamps(6);

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prism_athlete_publications');
    }
};
