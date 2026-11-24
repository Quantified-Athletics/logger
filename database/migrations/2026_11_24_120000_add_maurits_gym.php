<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the 'maurits' gym ("Maurits PT"), which Prism lists in `GYMS`.
 * Prism's sync rows carry a gym_id foreign key, so the row must exist before
 * any Prism data is written for it. Idempotent: GymSeeder seeds it too.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('gyms')->where('id', 'maurits')->exists()) {
            return;
        }

        DB::table('gyms')->insert([
            'id' => 'maurits',
            'name' => 'Maurits PT',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('gyms')->where('id', 'maurits')->delete();
    }
};
