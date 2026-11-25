<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Prism program snapshot a coach sent to one athlete. Each publish is a new row; the latest by
 * `published_at` is the athlete's current version unless it is revoked. A sibling of
 * `prism_athlete_publications`, which feeds the Flagship import and must never carry these.
 *
 * `raw` is the program file as JSON text, stored opaquely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prism_coach_publications', function (Blueprint $table): void {
            $table->id();
            $table->string('gym_id');
            $table->string('program_uuid');
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->date('start_date');
            $table->longText('raw');
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('published_at', 6);
            $table->dateTime('revoked_at', 6)->nullable();
            $table->timestamps(6);

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->index(['recipient_user_id', 'program_uuid', 'published_at'], 'prism_coach_publications_recipient_program_published_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prism_coach_publications');
    }
};
