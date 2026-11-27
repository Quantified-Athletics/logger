<?php

namespace Tests\Unit\Services\ExerciseTypes;

use App\Models\Exercise;
use App\Models\User;
use App\Services\ExerciseTypes\ExerciseTypeFactory;
use App\Services\ExerciseTypes\ExerciseTypeInterface;
use App\Services\ExerciseTypes\LogTypeTable;
use App\Services\PR\PrEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Golden test for the log type table (issue #33, step 1).
 *
 * The fixture below is the log_type → exercise_type pairing measured on the local copy of production
 * (2026-11-27). For every log type, the strategy the table picks must behave exactly like the strategy
 * today's exercise_type path picks for an exercise carrying that pair.
 */
class LogTypeTableTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function measuredLogTypes(): array
    {
        $measured = [
            'barbell' => 'regular',
            'barbell-complex' => 'regular',
            'dual-dumbbell' => 'regular',
            'single-dumbbell' => 'regular',
            'kettlebell' => 'regular',
            'ball' => 'regular',
            'machine' => 'regular',
            'added-weight' => 'regular',
            'bodyweight' => 'bodyweight',
            'bodyweight-reps' => 'bodyweight',
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
        foreach ($measured as $logType => $exerciseType) {
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
    #[DataProvider('measuredLogTypes')]
    public function the_table_gives_the_strategy_todays_exercise_type_gives(string $logType, string $exerciseType): void
    {
        $this->assertSame($exerciseType, LogTypeTable::strategyFor($logType));
    }

    #[Test]
    #[DataProvider('measuredLogTypes')]
    public function the_tables_strategy_behaves_like_todays(string $logType, string $exerciseType): void
    {
        $today = $this->todaysStrategy($exerciseType);
        $fromTable = ExerciseTypeFactory::create(new Exercise(['log_type' => $logType]));

        $this->assertSame(get_class($today), get_class($fromTable));

        foreach ([null, User::factory()->create(), $this->kilogramUser()] as $user) {
            $this->assertSame($today->getFormFields(), $fromTable->getFormFields());
            $this->assertSame($today->getFormFieldDefinitions([], $user), $fromTable->getFormFieldDefinitions([], $user));
            $this->assertSame($today->getFieldLabels($user), $fromTable->getFieldLabels($user));
            $this->assertSame($today->getFieldIncrements($user), $fromTable->getFieldIncrements($user));
            $this->assertEquals($today->getValidationRules($user), $fromTable->getValidationRules($user));
        }

        $this->assertSame($today->getSupportedProgressionTypes(), $fromTable->getSupportedProgressionTypes());
        $this->assertSame($today->getChartType(), $fromTable->getChartType());
        $this->assertSame($today->canCalculate1RM(), $fromTable->canCalculate1RM());
        $this->assertSame($today->getTypeName(), $fromTable->getTypeName());
    }

    #[Test]
    #[DataProvider('measuredLogTypes')]
    public function the_table_gives_the_pr_family_todays_exercise_type_gives(string $logType, string $exerciseType): void
    {
        $engine = app(PrEngine::class);

        $this->assertSame(
            $engine->resolveFamily($logType, $exerciseType),
            $engine->resolveFamily($logType, LogTypeTable::strategyFor($logType)),
        );
    }

    #[Test]
    public function the_table_lists_exactly_the_measured_log_types(): void
    {
        $expected = array_keys(self::measuredLogTypes());
        $actual = LogTypeTable::logTypes();

        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual);
    }

    #[Test]
    public function every_log_type_in_the_pr_families_has_an_entry(): void
    {
        $exerciseTypes = array_keys(config('exercise_types.types'));

        foreach (array_keys(config('pr_families.logTypeToFamily')) as $key) {
            if (in_array($key, $exerciseTypes, true)) {
                continue;
            }

            $this->assertNotNull(LogTypeTable::strategyFor($key), "pr_families log type '{$key}' has no entry in the log type table");
        }
    }

    #[Test]
    public function every_strategy_in_the_table_is_a_configured_exercise_type(): void
    {
        foreach (LogTypeTable::logTypes() as $logType) {
            $this->assertTrue(
                ExerciseTypeFactory::isTypeSupported(LogTypeTable::strategyFor($logType)),
                "log type '{$logType}' maps to an unconfigured strategy"
            );
        }
    }

    #[Test]
    public function unknown_and_empty_log_types_have_no_strategy(): void
    {
        $this->assertNull(LogTypeTable::strategyFor('not-a-log-type'));
        $this->assertNull(LogTypeTable::strategyFor(''));
        $this->assertNull(LogTypeTable::strategyFor(null));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function oldClientExerciseTypes(): array
    {
        return [
            'regular' => ['regular', 'barbell'],
            'bodyweight' => ['bodyweight', 'bodyweight'],
            'banded' => ['banded', 'banded'],
            'banded_resistance' => ['banded_resistance', 'banded'],
            'banded_assistance' => ['banded_assistance', 'banded'],
            'cardio' => ['cardio', 'cardio-distance'],
            'static_hold' => ['static_hold', 'static-hold'],
            'timed_output' => ['timed_output', 'timed-reps'],
            'load_output' => ['load_output', 'weighted-carry'],
        ];
    }

    #[Test]
    #[DataProvider('oldClientExerciseTypes')]
    public function an_old_clients_exercise_type_gets_its_log_type(string $exerciseType, string $logType): void
    {
        $this->assertSame($logType, LogTypeTable::logTypeForExerciseType($exerciseType));
    }

    #[Test]
    public function every_configured_exercise_type_has_a_log_type_for_old_clients(): void
    {
        foreach (array_keys(config('exercise_types.types')) as $exerciseType) {
            $logType = LogTypeTable::logTypeForExerciseType($exerciseType);

            $this->assertNotNull($logType, "exercise type '{$exerciseType}' has no log type for old clients");
            $this->assertNotNull(LogTypeTable::strategyFor($logType));
        }
    }

    #[Test]
    public function an_old_clients_exercise_type_round_trips_to_its_strategy_where_a_log_type_carries_it(): void
    {
        foreach (['regular', 'bodyweight', 'banded_resistance', 'cardio', 'static_hold', 'timed_output', 'load_output'] as $exerciseType) {
            $this->assertSame($exerciseType, LogTypeTable::strategyFor(LogTypeTable::logTypeForExerciseType($exerciseType)));
        }
    }

    #[Test]
    public function an_unknown_exercise_type_has_no_log_type(): void
    {
        $this->assertNull(LogTypeTable::logTypeForExerciseType('not-a-type'));
        $this->assertNull(LogTypeTable::logTypeForExerciseType(null));
    }

    /**
     * The strategy today's exercise_type path picks: the one configured under that name.
     */
    private function todaysStrategy(string $exerciseType): ExerciseTypeInterface
    {
        return ExerciseTypeFactory::createFromTypeName($exerciseType);
    }

    private function kilogramUser(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['weight_unit' => 'kg'])->save();

        return $user;
    }
}
