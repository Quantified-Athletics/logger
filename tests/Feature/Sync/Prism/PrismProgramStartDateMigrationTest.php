<?php

namespace Tests\Feature\Sync\Prism;

use App\Sync\Models\Gym;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * One-time server conversion — docs/plans/prism-program-partial-updates.md §6.1.
 */
class PrismProgramStartDateMigrationTest extends TestCase
{
    use RefreshDatabase;

    private Migration $migration;

    protected function setUp(): void
    {
        parent::setUp();
        Gym::create(['id' => 'the-cove', 'name' => 'The Cove']);
        $this->migration = require database_path('migrations/2026_10_31_120000_move_prism_program_metadata_to_start_date.php');

        // Step back to the pre-cutover schema (metadata blob, no start_date).
        $this->migration->down();
    }

    private function insertLegacy(string $uuid, ?array $metadata): void
    {
        DB::table('prism_programs')->insert([
            'gym_id' => 'the-cove',
            'program_uuid' => $uuid,
            'name' => "Program {$uuid}",
            'week_count' => 4,
            'metadata' => $metadata === null ? null : json_encode($metadata),
            'created_at' => '2026-10-01 12:00:00',
            'updated_at' => '2026-10-01 12:00:00',
        ]);
    }

    private function startDateOf(string $uuid): ?string
    {
        $value = DB::table('prism_programs')->where('program_uuid', $uuid)->value('start_date');

        return $value === null ? null : substr((string) $value, 0, 10);
    }

    public function test_up_moves_start_date_to_column_and_drops_metadata(): void
    {
        $this->insertLegacy('full', ['program' => 'Program full', 'startDate' => '2026-11-02', 'imported_at' => '2026-10-01T00:00:00Z']);
        $this->insertLegacy('no-date', ['program' => 'Program no-date']);
        $this->insertLegacy('null-meta', null);
        $this->insertLegacy('bad-date', ['startDate' => '2026-13-45']);
        $this->insertLegacy('wrong-type', ['startDate' => 20261102]);

        $this->migration->up();

        $this->assertFalse(Schema::hasColumn('prism_programs', 'metadata'));
        $this->assertTrue(Schema::hasColumn('prism_programs', 'start_date'));

        $this->assertSame('2026-11-02', $this->startDateOf('full'));
        $this->assertNull($this->startDateOf('no-date'));
        $this->assertNull($this->startDateOf('null-meta'));
        $this->assertNull($this->startDateOf('bad-date'));
        $this->assertNull($this->startDateOf('wrong-type'));
    }

    public function test_up_does_not_bump_updated_at(): void
    {
        $this->insertLegacy('full', ['startDate' => '2026-11-02']);

        $this->migration->up();

        $this->assertSame(
            '2026-10-01 12:00:00',
            substr((string) DB::table('prism_programs')->where('program_uuid', 'full')->value('updated_at'), 0, 19)
        );
    }

    public function test_down_restores_start_date_into_metadata(): void
    {
        $this->insertLegacy('full', ['program' => 'Program full', 'startDate' => '2026-11-02']);
        $this->insertLegacy('null-meta', null);
        $this->migration->up();

        $this->migration->down();

        $this->assertFalse(Schema::hasColumn('prism_programs', 'start_date'));
        $this->assertSame(
            ['startDate' => '2026-11-02'],
            json_decode((string) DB::table('prism_programs')->where('program_uuid', 'full')->value('metadata'), true)
        );
        $this->assertNull(DB::table('prism_programs')->where('program_uuid', 'null-meta')->value('metadata'));

        $this->migration->up(); // leave the schema as RefreshDatabase expects
    }
}
