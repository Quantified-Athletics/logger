<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('prism_programs', function (Blueprint $table): void {
            $table->string('tab_position', 64)->nullable()->after('archived_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prism_programs', function (Blueprint $table): void {
            $table->dropColumn('tab_position');
        });
    }
};
