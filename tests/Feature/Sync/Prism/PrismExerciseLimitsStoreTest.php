<?php

namespace Tests\Feature\Sync\Prism;

use App\Models\User;
use App\Sync\Models\Gym;
use App\Sync\Models\PrismExerciseCap;
use App\Sync\Models\PrismExerciseFamily;
use App\Sync\Models\PrismExerciseFamilyMember;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PrismExerciseLimitsStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Gym::create(['id' => 'the-cove', 'name' => 'The Cove']);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function invalidPayloads(): array
    {
        $noCapCountKey = ['family_id' => 'f', 'name' => 'F', 'cap_period' => null];
        $noCapPeriodKey = ['exercise_id' => 'e', 'cap_count' => null];
        $withoutCapsGroup = self::limitsPayload();
        unset($withoutCapsGroup['caps']);
        $withoutRemovals = self::limitsPayload();
        unset($withoutRemovals['members']['removals']);

        return [
            'family count zero' => ['families.upserts.0.cap_count', self::limitsPayload(['families' => ['upserts' => [['family_id' => 'f', 'name' => 'F', 'cap_count' => 0, 'cap_period' => 'week']]]])],
            'family count as string' => ['families.upserts.0.cap_count', self::limitsPayload(['families' => ['upserts' => [['family_id' => 'f', 'name' => 'F', 'cap_count' => '2', 'cap_period' => 'week']]]])],
            'family count without period and without count key' => ['families.upserts.0.cap_count', self::limitsPayload(['families' => ['upserts' => [$noCapCountKey]]])],
            'cap count negative' => ['caps.upserts.0.cap_count', self::limitsPayload(['caps' => ['upserts' => [['exercise_id' => 'e', 'cap_count' => -1, 'cap_period' => 'week']]]])],
            'cap count above uint32' => ['caps.upserts.0.cap_count', self::limitsPayload(['caps' => ['upserts' => [['exercise_id' => 'e', 'cap_count' => 4294967296, 'cap_period' => 'week']]]])],
            'cap count null with period' => ['caps.upserts.0.cap_count', self::limitsPayload(['caps' => ['upserts' => [['exercise_id' => 'e', 'cap_count' => null, 'cap_period' => 'week']]]])],
            'family period null with count' => ['families.upserts.0.cap_period', self::limitsPayload(['families' => ['upserts' => [['family_id' => 'f', 'name' => 'F', 'cap_count' => 2, 'cap_period' => null]]]])],
            'cap period not allowed' => ['caps.upserts.0.cap_period', self::limitsPayload(['caps' => ['upserts' => [['exercise_id' => 'e', 'cap_count' => 2, 'cap_period' => 'month']]]])],
            'cap period null with count' => ['caps.upserts.0.cap_period', self::limitsPayload(['caps' => ['upserts' => [['exercise_id' => 'e', 'cap_count' => 2, 'cap_period' => null]]]])],
            'cap without count and period keys' => ['caps.upserts.0.cap_period', self::limitsPayload(['caps' => ['upserts' => [$noCapPeriodKey]]])],
            'family name null' => ['families.upserts.0.name', self::limitsPayload(['families' => ['upserts' => [['family_id' => 'f', 'name' => null, 'cap_count' => null, 'cap_period' => null]]]])],
            'family name missing' => ['families.upserts.0.name', self::limitsPayload(['families' => ['upserts' => [['family_id' => 'f', 'cap_count' => null, 'cap_period' => null]]]])],
            'family name too long' => ['families.upserts.0.name', self::limitsPayload(['families' => ['upserts' => [['family_id' => 'f', 'name' => str_repeat('n', 256), 'cap_count' => null, 'cap_period' => null]]]])],
            'member is_member as string' => ['members.upserts.0.is_member', self::limitsPayload(['members' => ['upserts' => [['exercise_id' => 'e', 'family_id' => 'f', 'is_member' => '1']]]])],
            'member is_member missing' => ['members.upserts.0.is_member', self::limitsPayload(['members' => ['upserts' => [['exercise_id' => 'e', 'family_id' => 'f']]]])],
            'cap exercise_id too long' => ['caps.upserts.0.exercise_id', self::limitsPayload(['caps' => ['upserts' => [['exercise_id' => str_repeat('e', 256), 'cap_count' => null, 'cap_period' => null]]]])],
            'member removal without family_id' => ['members.removals.0.family_id', self::limitsPayload(['members' => ['removals' => [['exercise_id' => 'e']]]])],
            'caps group missing' => ['caps', $withoutCapsGroup],
            'member removals missing' => ['members.removals', $withoutRemovals],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function limitsPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'families' => ['upserts' => [], 'removals' => []],
            'members' => ['upserts' => [], 'removals' => []],
            'caps' => ['upserts' => [], 'removals' => []],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private static function samplePayload(): array
    {
        return self::limitsPayload([
            'families' => ['upserts' => [['family_id' => 'burpees', 'name' => 'Burpees', 'cap_count' => 2, 'cap_period' => 'week']]],
            'members' => ['upserts' => [['family_id' => 'clean', 'exercise_id' => 'clean-and-jerk', 'is_member' => true]]],
            'caps' => ['upserts' => [['exercise_id' => 'burpee-box-jump', 'cap_count' => 1, 'cap_period' => 'program']]],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function sampleRemovalPayload(): array
    {
        return self::limitsPayload([
            'families' => ['removals' => [['family_id' => 'burpees']]],
            'members' => ['removals' => [['family_id' => 'clean', 'exercise_id' => 'clean-and-jerk']]],
            'caps' => ['removals' => [['exercise_id' => 'burpee-box-jump']]],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postLimits(array $payload, string $gym = 'the-cove'): TestResponse
    {
        Sanctum::actingAs(User::factory()->create());

        return $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->postJson("/api/sync/prism/{$gym}/exercise-limits", $payload);
    }

    private function seedSampleRows(string $deviceId = 'dev-old'): void
    {
        PrismExerciseFamily::create(['gym_id' => 'the-cove', 'family_id' => 'burpees', 'name' => 'Burpees', 'cap_count' => 2, 'cap_period' => 'week', 'device_id' => $deviceId]);
        PrismExerciseFamilyMember::create(['gym_id' => 'the-cove', 'family_id' => 'clean', 'exercise_id' => 'clean-and-jerk', 'is_member' => true, 'device_id' => $deviceId]);
        PrismExerciseCap::create(['gym_id' => 'the-cove', 'exercise_id' => 'burpee-box-jump', 'cap_count' => 1, 'cap_period' => 'program', 'device_id' => $deviceId]);
    }

    private function assertTablesEmpty(): void
    {
        $this->assertSame(0, PrismExerciseFamily::query()->count());
        $this->assertSame(0, PrismExerciseFamilyMember::query()->count());
        $this->assertSame(0, PrismExerciseCap::query()->count());
    }

    public function test_upserts_store_a_row_in_each_table(): void
    {
        $response = $this->postLimits(self::samplePayload());

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonStructure(['updated_at']);

        $family = PrismExerciseFamily::query()->where('gym_id', 'the-cove')->where('family_id', 'burpees')->firstOrFail();
        $this->assertSame('Burpees', $family->name);
        $this->assertSame(2, $family->cap_count);
        $this->assertSame('week', $family->cap_period);
        $this->assertSame('dev-1', $family->device_id);
        $this->assertNull($family->deleted_at);

        $member = PrismExerciseFamilyMember::query()->where('gym_id', 'the-cove')->where('exercise_id', 'clean-and-jerk')->where('family_id', 'clean')->firstOrFail();
        $this->assertTrue($member->is_member);
        $this->assertSame('dev-1', $member->device_id);
        $this->assertNull($member->deleted_at);

        $cap = PrismExerciseCap::query()->where('gym_id', 'the-cove')->where('exercise_id', 'burpee-box-jump')->firstOrFail();
        $this->assertSame(1, $cap->cap_count);
        $this->assertSame('program', $cap->cap_period);
        $this->assertSame('dev-1', $cap->device_id);
        $this->assertNull($cap->deleted_at);
    }

    public function test_empty_lists_are_a_no_op(): void
    {
        $this->postLimits(self::limitsPayload())
            ->assertOk()
            ->assertJsonStructure(['updated_at']);

        $this->assertTablesEmpty();
    }

    public function test_removal_writes_a_tombstone_and_keeps_stored_values(): void
    {
        $this->seedSampleRows();

        $this->postLimits(self::sampleRemovalPayload())->assertOk();

        $family = PrismExerciseFamily::query()->where('family_id', 'burpees')->firstOrFail();
        $this->assertNotNull($family->deleted_at);
        $this->assertSame('dev-1', $family->device_id);
        $this->assertSame('Burpees', $family->name);
        $this->assertSame(2, $family->cap_count);
        $this->assertSame('week', $family->cap_period);

        $member = PrismExerciseFamilyMember::query()->where('exercise_id', 'clean-and-jerk')->firstOrFail();
        $this->assertNotNull($member->deleted_at);
        $this->assertSame('dev-1', $member->device_id);
        $this->assertTrue($member->is_member);

        $cap = PrismExerciseCap::query()->where('exercise_id', 'burpee-box-jump')->firstOrFail();
        $this->assertNotNull($cap->deleted_at);
        $this->assertSame('dev-1', $cap->device_id);
        $this->assertSame(1, $cap->cap_count);
        $this->assertSame('program', $cap->cap_period);
    }

    public function test_removal_with_no_prior_row_writes_a_placeholder_tombstone(): void
    {
        $this->postLimits(self::sampleRemovalPayload())->assertOk();

        $family = PrismExerciseFamily::query()->where('family_id', 'burpees')->firstOrFail();
        $this->assertSame('', $family->name);
        $this->assertNull($family->cap_count);
        $this->assertNull($family->cap_period);
        $this->assertNotNull($family->deleted_at);

        $member = PrismExerciseFamilyMember::query()->where('exercise_id', 'clean-and-jerk')->firstOrFail();
        $this->assertFalse($member->is_member);
        $this->assertNotNull($member->deleted_at);

        $cap = PrismExerciseCap::query()->where('exercise_id', 'burpee-box-jump')->firstOrFail();
        $this->assertNull($cap->cap_count);
        $this->assertNull($cap->cap_period);
        $this->assertNotNull($cap->deleted_at);
    }

    public function test_upsert_clears_a_tombstone(): void
    {
        $this->postLimits(self::sampleRemovalPayload())->assertOk();

        $this->postLimits(self::samplePayload())->assertOk();

        $this->assertSame(1, PrismExerciseFamily::query()->where('family_id', 'burpees')->count());
        $this->assertSame(1, PrismExerciseFamilyMember::query()->where('exercise_id', 'clean-and-jerk')->count());
        $this->assertSame(1, PrismExerciseCap::query()->where('exercise_id', 'burpee-box-jump')->count());

        $family = PrismExerciseFamily::query()->where('family_id', 'burpees')->firstOrFail();
        $this->assertNull($family->deleted_at);
        $this->assertSame('Burpees', $family->name);
        $this->assertSame(2, $family->cap_count);

        $this->assertNull(PrismExerciseFamilyMember::query()->where('exercise_id', 'clean-and-jerk')->firstOrFail()->deleted_at);
        $this->assertNull(PrismExerciseCap::query()->where('exercise_id', 'burpee-box-jump')->firstOrFail()->deleted_at);
    }

    public function test_key_in_both_lists_ends_up_removed(): void
    {
        $payload = self::limitsPayload([
            'families' => [
                'upserts' => [['family_id' => 'burpees', 'name' => 'Burpees', 'cap_count' => null, 'cap_period' => null]],
                'removals' => [['family_id' => 'burpees']],
            ],
            'members' => [
                'upserts' => [['family_id' => 'clean', 'exercise_id' => 'clean-and-jerk', 'is_member' => true]],
                'removals' => [['family_id' => 'clean', 'exercise_id' => 'clean-and-jerk']],
            ],
            'caps' => [
                'upserts' => [['exercise_id' => 'burpee-box-jump', 'cap_count' => 1, 'cap_period' => 'week']],
                'removals' => [['exercise_id' => 'burpee-box-jump']],
            ],
        ]);

        $this->postLimits($payload)->assertOk();

        $this->assertNotNull(PrismExerciseFamily::query()->where('family_id', 'burpees')->firstOrFail()->deleted_at);
        $this->assertNotNull(PrismExerciseFamilyMember::query()->where('exercise_id', 'clean-and-jerk')->firstOrFail()->deleted_at);
        $this->assertNotNull(PrismExerciseCap::query()->where('exercise_id', 'burpee-box-jump')->firstOrFail()->deleted_at);
    }

    public function test_live_null_cap_is_distinct_from_a_tombstone(): void
    {
        $this->postLimits(self::limitsPayload([
            'families' => ['upserts' => [['family_id' => 'f', 'name' => 'F', 'cap_count' => null, 'cap_period' => null]]],
            'caps' => ['upserts' => [['exercise_id' => 'e', 'cap_count' => null, 'cap_period' => null]]],
        ]))->assertOk();

        $family = PrismExerciseFamily::query()->where('family_id', 'f')->firstOrFail();
        $this->assertNull($family->cap_count);
        $this->assertNull($family->deleted_at);

        $cap = PrismExerciseCap::query()->where('exercise_id', 'e')->firstOrFail();
        $this->assertNull($cap->cap_count);
        $this->assertNull($cap->deleted_at);
    }

    public function test_live_non_member_is_distinct_from_a_tombstone(): void
    {
        $this->postLimits(self::limitsPayload([
            'members' => ['upserts' => [['family_id' => 'clean', 'exercise_id' => 'clean-and-jerk', 'is_member' => false]]],
        ]))->assertOk();

        $member = PrismExerciseFamilyMember::query()->where('exercise_id', 'clean-and-jerk')->firstOrFail();
        $this->assertFalse($member->is_member);
        $this->assertNull($member->deleted_at);
    }

    public function test_removing_a_family_leaves_its_member_rows_live(): void
    {
        PrismExerciseFamily::create(['gym_id' => 'the-cove', 'family_id' => 'burpees', 'name' => 'Burpees', 'device_id' => 'dev-old']);
        PrismExerciseFamilyMember::create(['gym_id' => 'the-cove', 'family_id' => 'burpees', 'exercise_id' => 'air-squat', 'is_member' => true, 'device_id' => 'dev-old']);

        $this->postLimits(self::limitsPayload([
            'families' => ['removals' => [['family_id' => 'burpees']]],
        ]))->assertOk();

        $this->assertNotNull(PrismExerciseFamily::query()->where('family_id', 'burpees')->firstOrFail()->deleted_at);
        $this->assertNull(PrismExerciseFamilyMember::query()->where('exercise_id', 'air-squat')->firstOrFail()->deleted_at);
    }

    public function test_family_ids_are_case_and_accent_sensitive(): void
    {
        $this->postLimits(self::limitsPayload([
            'families' => ['upserts' => [
                ['family_id' => 'burpees', 'name' => 'one', 'cap_count' => null, 'cap_period' => null],
                ['family_id' => 'Burpees', 'name' => 'two', 'cap_count' => null, 'cap_period' => null],
                ['family_id' => 'burpées', 'name' => 'three', 'cap_count' => null, 'cap_period' => null],
            ]],
        ]))->assertOk();

        $this->assertSame(3, PrismExerciseFamily::query()->count());
        $this->assertSame('one', PrismExerciseFamily::query()->where('family_id', 'burpees')->firstOrFail()->name);
        $this->assertSame('two', PrismExerciseFamily::query()->where('family_id', 'Burpees')->firstOrFail()->name);
        $this->assertSame('three', PrismExerciseFamily::query()->where('family_id', 'burpées')->firstOrFail()->name);
    }

    public function test_member_can_name_a_family_with_no_row(): void
    {
        $this->postLimits(self::limitsPayload([
            'members' => ['upserts' => [['family_id' => 'ghost-family', 'exercise_id' => 'air-squat', 'is_member' => true]]],
        ]))->assertOk();

        $this->assertSame(1, PrismExerciseFamilyMember::query()->where('family_id', 'ghost-family')->count());
        $this->assertSame(0, PrismExerciseFamily::query()->count());
    }

    public function test_one_exercise_can_belong_to_two_families(): void
    {
        $this->postLimits(self::limitsPayload([
            'members' => ['upserts' => [
                ['family_id' => 'squats', 'exercise_id' => 'air-squat', 'is_member' => true],
                ['family_id' => 'legs', 'exercise_id' => 'air-squat', 'is_member' => true],
            ]],
        ]))->assertOk();

        $this->postLimits(self::limitsPayload([
            'members' => ['removals' => [['family_id' => 'squats', 'exercise_id' => 'air-squat']]],
        ]))->assertOk();

        $this->assertSame(2, PrismExerciseFamilyMember::query()->count());
        $this->assertNotNull(PrismExerciseFamilyMember::query()->where('family_id', 'squats')->firstOrFail()->deleted_at);
        $this->assertNull(PrismExerciseFamilyMember::query()->where('family_id', 'legs')->firstOrFail()->deleted_at);
    }

    public function test_failure_part_way_leaves_all_three_tables_unchanged(): void
    {
        PrismExerciseFamily::create(['gym_id' => 'the-cove', 'family_id' => 'seeded', 'name' => 'Seeded', 'cap_count' => 1, 'cap_period' => 'week', 'device_id' => 'dev-old']);
        PrismExerciseFamilyMember::create(['gym_id' => 'the-cove', 'family_id' => 'seeded', 'exercise_id' => 'air-squat', 'is_member' => true, 'device_id' => 'dev-old']);
        PrismExerciseCap::create(['gym_id' => 'the-cove', 'exercise_id' => 'air-squat', 'cap_count' => 1, 'cap_period' => 'week', 'device_id' => 'dev-old']);

        $thrown = false;
        DB::listen(function (QueryExecuted $query) use (&$thrown): void {
            if (! $thrown && str_contains($query->sql, 'prism_exercise_caps')) {
                $thrown = true;
                throw new RuntimeException('forced failure');
            }
        });

        $this->postLimits(self::limitsPayload([
            'families' => ['upserts' => [
                ['family_id' => 'seeded', 'name' => 'Renamed', 'cap_count' => 1, 'cap_period' => 'week'],
                ['family_id' => 'second', 'name' => 'Second', 'cap_count' => null, 'cap_period' => null],
            ]],
            'members' => ['upserts' => [['family_id' => 'seeded', 'exercise_id' => 'air-squat', 'is_member' => false]]],
            'caps' => ['upserts' => [['exercise_id' => 'air-squat', 'cap_count' => 5, 'cap_period' => 'week']]],
        ]))->assertStatus(500);

        $this->assertTrue($thrown);
        $this->assertSame(1, PrismExerciseFamily::query()->count());
        $this->assertSame('Seeded', PrismExerciseFamily::query()->where('family_id', 'seeded')->firstOrFail()->name);
        $this->assertSame('dev-old', PrismExerciseFamily::query()->where('family_id', 'seeded')->firstOrFail()->device_id);
        $this->assertTrue(PrismExerciseFamilyMember::query()->where('family_id', 'seeded')->firstOrFail()->is_member);
        $this->assertSame(1, PrismExerciseCap::query()->where('exercise_id', 'air-squat')->firstOrFail()->cap_count);
    }

    public function test_response_updated_at_matches_the_feed_row(): void
    {
        $response = $this->postLimits(self::limitsPayload([
            'families' => ['upserts' => [['family_id' => 'burpees', 'name' => 'Burpees', 'cap_count' => null, 'cap_period' => null]]],
        ]));
        $response->assertOk();

        $feed = $this->withHeaders(['X-Device-Id' => 'dev-1'])
            ->getJson('/api/sync/prism/the-cove/changes')
            ->assertOk();

        $this->assertSame($response->json('updated_at'), $feed->json('exercise_families.0.updated_at'));
    }

    public function test_unknown_gym_returns_404(): void
    {
        $this->postLimits(self::samplePayload(), 'no-such-gym')->assertNotFound();
    }

    public function test_unauthenticated_returns_401(): void
    {
        $this->postJson('/api/sync/prism/the-cove/exercise-limits', self::samplePayload())
            ->assertUnauthorized();
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_payload_returns_422(string $errorKey, array $payload): void
    {
        $response = $this->postLimits($payload)
            ->assertStatus(422)
            ->assertJson(['status' => 'error']);

        $this->assertStringContainsString($errorKey, $response->json('message'));
        $this->assertTablesEmpty();
    }
}
