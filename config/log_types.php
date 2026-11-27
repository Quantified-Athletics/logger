<?php

/**
 * Log Types
 *
 * Athlete's log types as Logger knows them (`config/pr_families.php` mirrors Athlete's
 * LOG_TYPE_TO_FAMILY). Each log type uses exactly one exercise type strategy from
 * `config/exercise_types.php`; this table is the one place that says which.
 *
 * `from_exercise_type` is the reverse table for old sync clients that send only an
 * `exercise_type`: it names the log type to store for each one.
 */

return [
    'strategies' => [
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

        // Rest: nothing is logged against it.
        'none' => 'regular',
    ],

    'from_exercise_type' => [
        'regular' => 'barbell',
        'bodyweight' => 'bodyweight',
        'banded' => 'banded',
        'banded_resistance' => 'banded',
        'banded_assistance' => 'banded',
        'cardio' => 'cardio-distance',
        'static_hold' => 'static-hold',
        'timed_output' => 'timed-reps',
        'load_output' => 'weighted-carry',
    ],
];
