<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shared exercise record's fields (#42), one nullable column each, named by the snake_case rule.
 * A list, an either-or list or a map is a JSON column. The record's `id` is `canonical_name`, `name`
 * is `name`, `logType` is `log_type`, `createdAt` is the existing `created_at`, and `aliases` stay in
 * `exercise_aliases`, so none of those gets a column here. Additive only: every existing row reads the
 * new fields as absent.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $columns = [
        'former_ids', 'equipment', 'primary_muscles', 'core_muscles', 'regions_loaded', 'primary_movements',
        'core_movements', 'motion', 'modalities', 'kinds', 'laterality', 'families', 'category', 'max_per_week',
        'repeat_cooldown', 'suggestion_count', 'suggestion_period', 'video', 'generator_eligible',
        'historic_reps', 'modifier', 'members', 'bucket', 'tags',
    ];

    public function up(): void
    {
        Schema::table('exercises', function (Blueprint $table) {
            $table->json('former_ids')->nullable();
            $table->json('equipment')->nullable();
            $table->json('primary_muscles')->nullable();
            $table->json('core_muscles')->nullable();
            $table->json('regions_loaded')->nullable();
            $table->json('primary_movements')->nullable();
            $table->json('core_movements')->nullable();
            $table->string('motion', 50)->nullable();
            $table->json('modalities')->nullable();
            $table->json('kinds')->nullable();
            $table->string('laterality', 50)->nullable();
            $table->json('families')->nullable();
            $table->string('category')->nullable();
            $table->unsignedInteger('max_per_week')->nullable();
            $table->unsignedInteger('repeat_cooldown')->nullable();
            $table->unsignedInteger('suggestion_count')->nullable();
            $table->string('suggestion_period', 50)->nullable();
            $table->string('video', 2048)->nullable();
            $table->boolean('generator_eligible')->nullable();
            $table->json('historic_reps')->nullable();
            $table->string('modifier', 50)->nullable();
            $table->json('members')->nullable();
            $table->string('bucket')->nullable();
            $table->json('tags')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('exercises', function (Blueprint $table) {
            $table->dropColumn($this->columns);
        });
    }
};
