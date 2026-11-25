<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An athlete's own workout-queue items (generated and custom workouts), so they survive a new phone
 * or a cleared browser. `item` is the whole item as JSON, stored opaquely; Logger reads only its
 * `id`, `updatedAt` and `source`. `deleted_at` is a tombstone, kept so other devices learn of a
 * deletion, and a newer push can lift it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('athlete_queue_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('item_id', 191);
            $table->json('item');
            $table->dateTime('item_updated_at', 6);
            $table->dateTime('deleted_at', 6)->nullable();
            $table->timestamps(6);

            $table->unique(['user_id', 'item_id'], 'athlete_queue_items_user_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('athlete_queue_items');
    }
};
