<?php

namespace Tests\Feature\ExerciseSchema;

use App\Models\Exercise;
use App\Models\User;
use App\Services\ExerciseTypes\ExerciseTypeFactory;
use App\Services\PR\PrEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An exercise that carries only a log_type behaves exactly as today's exercise_type pairing for that
 * log type: same strategy, form fields, chart, progression, PR family, and the same `exerciseType` in
 * the sync changes and restore responses.
 */
class LogTypeReadersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Today's pairing, measured on the local copy of production (issue #33). `added-weight` had no row
     * to measure: it is bodyweight, as in Athlete and `pr_families.php`.
     *
     * @return array<string, array{string, string}>
     */
    public static function measuredPairings(): array
    {
        $pairings = [
            'barbell' => 'regular',
            'barbell-complex' => 'regular',
            'dual-dumbbell' => 'regular',
            'single-dumbbell' => 'regular',
            'kettlebell' => 'regular',
            'ball' => 'regular',
            'machine' => 'regular',
            'bodyweight' => 'bodyweight',
            'bodyweight-reps' => 'bodyweight',
            'added-weight' => 'bodyweight',
            'banded' => 'banded_resistance',
            'cardio' => 'cardio',
            'cardio-calories' => 'cardio',
            'cardio-distance' => 'cardio',
            'static-hold' => 'static_hold',
            'timed-reps' => 'timed_output',
            'sled' => 'load_output',
            'weighted-carry' => 'load_output',
            'weighted-carry-1-db' => 'load_output',
            'weighted-carry-1-kb' => 'load_output',
            'weighted-carry-2-db' => 'load_output',
            'weighted-carry-2-kb' => 'load_output',
            'weighted-carry-ball' => 'load_output',
            'none' => 'regular',
        ];

        $cases = [];
        foreach ($pairings as $logType => $exerciseType) {
            $cases[$logType] = [$logType, $exerciseType];
        }

        return $cases;
    }

    protected function setUp(): void
    {
        parent::setUp();
        ExerciseTypeFactory::clearCache();
    }

    #[Test]
    #[DataProvider('measuredPairings')]
    public function a_standard_exercise_with_only_a_log_type_gets_todays_strategy(string $logType, string $exerciseType): void
    {
        $exercise = $this->standardExercise($logType);
        $expected = ExerciseTypeFactory::createFromTypeName($exerciseType);
        $actual = $exercise->fresh()->getTypeStrategy();

        $this->assertSame(get_class($expected), get_class($actual));
        $this->assertSame($expected->getFormFields(), $actual->getFormFields());
        $this->assertEquals($expected->getFormFieldDefinitions(), $actual->getFormFieldDefinitions());
        $this->assertSame($expected->getFieldLabels(), $actual->getFieldLabels());
        $this->assertSame($expected->getFieldIncrements(), $actual->getFieldIncrements());
        $this->assertEquals($expected->getValidationRules(), $actual->getValidationRules());
        $this->assertSame($expected->getSupportedProgressionTypes(), $actual->getSupportedProgressionTypes());
        $this->assertSame($expected->getChartType(), $actual->getChartType());
        $this->assertSame($expected->canCalculate1RM(), $actual->canCalculate1RM());
    }

    #[Test]
    #[DataProvider('measuredPairings')]
    public function a_standard_exercise_with_only_a_log_type_gets_todays_pr_family(string $logType, string $exerciseType): void
    {
        $exercise = $this->standardExercise($logType)->fresh();
        $engine = app(PrEngine::class);

        $this->assertSame(
            $engine->resolveFamily($logType, $exerciseType),
            $engine->resolveFamilyForLogType($exercise->log_type),
        );
    }

    #[Test]
    #[DataProvider('measuredPairings')]
    public function the_exercise_helpers_answer_from_the_log_type(string $logType, string $exerciseType): void
    {
        $exercise = $this->standardExercise($logType)->fresh();

        $this->assertSame($exerciseType, $exercise->strategyTypeName());
        $this->assertSame($exerciseType === 'cardio', $exercise->isCardio());
        $this->assertSame($exerciseType === 'static_hold', $exercise->isStaticHold());
        $this->assertSame($exerciseType === 'banded_resistance', $exercise->isBanded());
        $this->assertTrue($exercise->isType($exerciseType));
    }

    #[Test]
    #[DataProvider('measuredPairings')]
    public function the_model_scopes_select_by_log_type(string $logType, string $exerciseType): void
    {
        $exercise = $this->standardExercise($logType);

        $this->assertTrue(Exercise::ofType($exerciseType)->whereKey($exercise->id)->exists());
        $this->assertSame($exerciseType === 'cardio', Exercise::cardio()->whereKey($exercise->id)->exists());
        $this->assertSame($exerciseType !== 'cardio', Exercise::nonCardio()->whereKey($exercise->id)->exists());
        $this->assertSame($exerciseType === 'regular', Exercise::regular()->whereKey($exercise->id)->exists());
        $this->assertSame($exerciseType === 'bodyweight', Exercise::bodyweight()->whereKey($exercise->id)->exists());
        $this->assertSame($exerciseType === 'banded_resistance', Exercise::banded()->whereKey($exercise->id)->exists());
        $this->assertSame($exerciseType === 'static_hold', Exercise::staticHold()->whereKey($exercise->id)->exists());
    }

    #[Test]
    public function the_sync_changes_and_restore_responses_send_todays_exercise_type(): void
    {
        $user = User::factory()->create();
        $expected = [];
        foreach (self::measuredPairings() as [$logType, $exerciseType]) {
            $exercise = Exercise::factory()->create([
                'title' => 'Mine '.$logType,
                'user_id' => $user->id,
                'log_type' => $logType,
            ]);
            $expected[$exercise->canonical_name] = ['logType' => $logType, 'exerciseType' => $exerciseType];
        }
        Sanctum::actingAs($user, ['*']);

        foreach (['/api/sync/changes', '/api/sync/restore'] as $endpoint) {
            $sent = collect($this->getJson($endpoint)->assertOk()->json('userExercises'))
                ->mapWithKeys(fn (array $row) => [$row['id'] => ['logType' => $row['logType'], 'exerciseType' => $row['exerciseType']]])
                ->all();

            $this->assertEquals($expected, $sent, $endpoint);
        }
    }

    private function standardExercise(string $logType): Exercise
    {
        return Exercise::factory()->create([
            'title' => 'Standard '.$logType,
            'user_id' => null,
            'log_type' => $logType,
        ]);
    }
}
