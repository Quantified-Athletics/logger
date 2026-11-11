<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Momence publish settings for a program's `momence` destination row. Null on
 * `google_sheets` rows, as the Sheets columns are null on `momence` rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prism_program_destinations', function (Blueprint $table): void {
            $table->string('momence_track', 8)->nullable()->after('title');
            $table->string('momence_cycle_prefix')->nullable()->after('momence_track');
            $table->string('momence_bridge_prefix')->nullable()->after('momence_cycle_prefix');
            $table->unsignedSmallInteger('momence_bridge_weeks')->nullable()->after('momence_bridge_prefix');
        });
    }

    public function down(): void
    {
        Schema::table('prism_program_destinations', function (Blueprint $table): void {
            $table->dropColumn([
                'momence_track',
                'momence_cycle_prefix',
                'momence_bridge_prefix',
                'momence_bridge_weeks',
            ]);
        });
    }
};
