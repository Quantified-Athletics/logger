<?php

namespace Tests\Feature\Sync\Prism;

use App\Models\User;
use App\Sync\Models\Gym;
use App\Sync\Models\PrismProgram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Partial program writes — docs/plans/prism-program-partial-updates.md §3, §5, §6.3.
 */
class PrismProgramStoreTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/sync/prism/the-cove/programs/prog-uuid-123';

    protected function setUp(): void
    {
        parent::setUp();
        Gym::create(['id' => 'the-cove', 'name' => 'The Cove']);
    }

    private function pushProgram(array $payload, string $deviceId = 'dev-uuid-1', string $url = self::URL): TestResponse
    {
        return $this->withHeaders(['X-Device-Id' => $deviceId])->postJson($url, $payload);
    }

    private function seedProgram(): PrismProgram
    {
        Sanctum::actingAs(User::factory()->create());

        $this->pushProgram([
            'name' => 'Spring Block',
            'week_count' => 6,
            'start_date' => '2026-11-02',
            'archived_at' => '2026-10-21T10:00:00Z',
        ])->assertStatus(200);

        return PrismProgram::firstOrFail();
    }

    public function test_create_stores_all_fields(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->pushProgram([
            'name' => 'Hypertrophy Block A',
            'week_count' => 6,
            'start_date' => '2026-11-02',
        ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'ok'])
            ->assertJsonStructure(['status', 'updated_at']);

        $this->assertDatabaseCount('prism_programs', 1);
        $program = PrismProgram::first();
        $this->assertSame('the-cove', $program->gym_id);
        $this->assertSame('prog-uuid-123', $program->program_uuid);
        $this->assertSame('Hypertrophy Block A', $program->name);
        $this->assertSame(6, $program->week_count);
        $this->assertSame('2026-11-02', substr((string) $program->start_date, 0, 10));
        $this->assertNull($program->archived_at);
        $this->assertSame('dev-uuid-1', $program->device_id);
        $this->assertSame($program->updated_at->toIso8601String(), $response->json('updated_at'));
    }

    public function test_create_without_name_or_week_count_returns_422(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->pushProgram(['week_count' => 6])->assertStatus(422)->assertJson(['status' => 'error']);
        $this->pushProgram(['name' => 'No weeks'])->assertStatus(422)->assertJson(['status' => 'error']);
        $this->pushProgram(['start_date' => '2026-11-02'])->assertStatus(422);

        $this->assertDatabaseCount('prism_programs', 0);
    }

    public function test_update_without_name_or_week_count_is_accepted(): void
    {
        $this->seedProgram();

        $this->pushProgram(['start_date' => '2026-12-07'])->assertStatus(200);
    }

    public function test_explicit_null_name_on_update_returns_422(): void
    {
        $this->seedProgram();

        $this->pushProgram(['name' => null])->assertStatus(422);
        $this->assertSame('Spring Block', PrismProgram::firstOrFail()->name);
    }

    public function test_rename_only_leaves_other_fields_untouched(): void
    {
        // F1: a rename must not carry stale values for other fields.
        $program = $this->seedProgram();

        $this->pushProgram(['name' => 'Spring Block 2026'], 'dev-uuid-2')->assertStatus(200);

        $program->refresh();
        $this->assertSame('Spring Block 2026', $program->name);
        $this->assertSame(6, $program->week_count);
        $this->assertSame('2026-11-02', substr((string) $program->start_date, 0, 10));
        $this->assertNotNull($program->archived_at);
        $this->assertSame('dev-uuid-2', $program->device_id);
    }

    public function test_archive_only_leaves_other_fields_untouched_and_null_unarchives(): void
    {
        // F4: archiving / un-archiving touches nothing else.
        $program = $this->seedProgram();

        $this->pushProgram(['archived_at' => null])->assertStatus(200);
        $program->refresh();
        $this->assertNull($program->archived_at);
        $this->assertSame('Spring Block', $program->name);
        $this->assertSame('2026-11-02', substr((string) $program->start_date, 0, 10));

        $this->pushProgram(['archived_at' => '2026-10-30T09:00:00Z'])->assertStatus(200);
        $program->refresh();
        $this->assertSame('2026-10-30 09:00:00', $program->archived_at->format('Y-m-d H:i:s'));
        $this->assertSame('Spring Block', $program->name);
    }

    public function test_start_date_set_clear_and_untouched_when_absent(): void
    {
        // F2: the start date only changes when it is sent.
        $program = $this->seedProgram();

        $this->pushProgram(['start_date' => '2026-12-07'])->assertStatus(200);
        $this->assertSame('2026-12-07', substr((string) $program->fresh()->start_date, 0, 10));

        $this->pushProgram(['name' => 'Renamed'])->assertStatus(200);
        $this->assertSame('2026-12-07', substr((string) $program->fresh()->start_date, 0, 10));

        $this->pushProgram(['start_date' => null])->assertStatus(200);
        $this->assertNull($program->fresh()->start_date);
    }

    public function test_concurrent_edits_to_different_fields_both_survive(): void
    {
        // Two coaches, each sending only the field they changed.
        $program = $this->seedProgram();

        $this->pushProgram(['name' => 'Coach A rename'], 'dev-a')->assertStatus(200);
        $this->pushProgram(['start_date' => '2027-01-04'], 'dev-b')->assertStatus(200);

        $program->refresh();
        $this->assertSame('Coach A rename', $program->name);
        $this->assertSame('2027-01-04', substr((string) $program->start_date, 0, 10));
    }

    public function test_invalid_start_date_returns_422(): void
    {
        $this->seedProgram();

        $this->pushProgram(['start_date' => '2026-13-01'])->assertStatus(422);
        $this->pushProgram(['start_date' => '02-11-2026'])->assertStatus(422);
        $this->pushProgram(['start_date' => '2026-11-02T00:00:00Z'])->assertStatus(422);
    }

    public function test_legacy_metadata_write_returns_503_with_retry_after_and_writes_nothing(): void
    {
        // §6.3: an old Prism build keeps the edit queued (5xx = transient) instead of dropping it (4xx).
        $program = $this->seedProgram();

        $response = $this->pushProgram([
            'name' => 'Stale name',
            'week_count' => 6,
            'metadata' => ['program' => 'Stale name', 'startDate' => '2026-01-01'],
            'archived_at' => null,
        ]);

        $response->assertStatus(503)->assertJson(['status' => 'error']);
        $this->assertNotEmpty($response->headers->get('Retry-After'));

        $program->refresh();
        $this->assertSame('Spring Block', $program->name);
        $this->assertSame('2026-11-02', substr((string) $program->start_date, 0, 10));
        $this->assertNotNull($program->archived_at);
    }

    public function test_legacy_metadata_null_also_returns_503(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->pushProgram(['name' => 'X', 'week_count' => 1, 'metadata' => null])->assertStatus(503);
        $this->assertDatabaseCount('prism_programs', 0);
    }

    public function test_every_accepted_write_bumps_updated_at_even_when_identical(): void
    {
        $program = $this->seedProgram();
        $before = $program->updated_at;

        Carbon::setTestNow($before->copy()->addSeconds(5));
        try {
            $response = $this->pushProgram(['name' => 'Spring Block'])->assertStatus(200);
        } finally {
            Carbon::setTestNow();
        }

        $program->refresh();
        $this->assertTrue($program->updated_at->gt($before));
        $this->assertSame($program->updated_at->toIso8601String(), $response->json('updated_at'));
    }

    public function test_create_race_is_applied_as_update(): void
    {
        Sanctum::actingAs(User::factory()->create());

        // Simulate another coach inserting the same program between our lookup and our insert.
        $inserted = false;
        PrismProgram::creating(function () use (&$inserted): void {
            if ($inserted) {
                return;
            }
            $inserted = true;
            DB::table('prism_programs')->insert([
                'gym_id' => 'the-cove',
                'program_uuid' => 'prog-uuid-123',
                'name' => 'Created by the other coach',
                'week_count' => 4,
                'start_date' => '2026-11-02',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->pushProgram(['name' => 'Our name', 'week_count' => 6])->assertStatus(200);

        $this->assertDatabaseCount('prism_programs', 1);
        $program = PrismProgram::firstOrFail();
        $this->assertSame('Our name', $program->name);
        $this->assertSame(6, $program->week_count);
        // Not in our payload ⇒ the other coach's value survives.
        $this->assertSame('2026-11-02', substr((string) $program->start_date, 0, 10));
    }

    public function test_unknown_gym_returns_404(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->pushProgram(['name' => 'Hypertrophy Block A', 'week_count' => 6], 'dev-uuid-1', '/api/sync/prism/nonexistent-gym/programs/prog-uuid-123')
            ->assertStatus(404)
            ->assertJson(['status' => 'error', 'message' => 'Gym not found.']);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->pushProgram(['name' => 'Hypertrophy Block A', 'week_count' => 6])
            ->assertStatus(401)
            ->assertJson(['status' => 'error', 'message' => 'Unauthenticated.']);
    }
}
