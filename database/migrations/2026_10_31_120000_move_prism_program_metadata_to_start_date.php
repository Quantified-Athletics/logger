<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Promote the only shared key of prism_programs.metadata (startDate) to a first-class
 * start_date column, then drop the metadata blob. The other keys are dropped on purpose:
 * `program` duplicates `name`, `imported_at` is device-local provenance.
 *
 * See docs/plans/prism-program-partial-updates.md §2 and §6.1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prism_programs', function (Blueprint $table): void {
            $table->date('start_date')->nullable()->after('week_count');
        });

        // Backfill in PHP so the migration behaves identically on MySQL and SQLite.
        // updated_at is deliberately left untouched (§6.1).
        DB::table('prism_programs')
            ->whereNotNull('metadata')
            ->orderBy('id')
            ->select(['id', 'metadata'])
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $metadata = json_decode((string) $row->metadata, true);
                    $startDate = is_array($metadata) ? ($metadata['startDate'] ?? null) : null;

                    if (! is_string($startDate) || ! self::isIsoDate($startDate)) {
                        continue;
                    }

                    DB::table('prism_programs')->where('id', $row->id)->update(['start_date' => $startDate]);
                }
            });

        Schema::table('prism_programs', function (Blueprint $table): void {
            $table->dropColumn('metadata');
        });
    }

    public function down(): void
    {
        Schema::table('prism_programs', function (Blueprint $table): void {
            $table->json('metadata')->nullable()->after('week_count');
        });

        // Only startDate can be restored; `program` and `imported_at` are gone for good.
        DB::table('prism_programs')
            ->whereNotNull('start_date')
            ->orderBy('id')
            ->select(['id', 'start_date'])
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('prism_programs')->where('id', $row->id)->update([
                        'metadata' => json_encode(['startDate' => substr((string) $row->start_date, 0, 10)]),
                    ]);
                }
            });

        Schema::table('prism_programs', function (Blueprint $table): void {
            $table->dropColumn('start_date');
        });
    }

    private static function isIsoDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
};
