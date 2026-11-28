<?php

namespace App\ExerciseLibrary;

/**
 * The shared exercise record's fields, each with the column that carries it on `exercises` and the
 * kind of value it holds. The record's `id` is `canonical_name`; `aliases` live in
 * `exercise_aliases`, so it has no column; `createdAt` is the existing `created_at` timestamp.
 */
class RecordFields
{
    public const KIND_STRING = 'string';

    public const KIND_INTEGER = 'integer';

    public const KIND_BOOLEAN = 'boolean';

    public const KIND_LIST = 'list';

    public const KIND_MAP = 'map';

    public const KIND_TIMESTAMP = 'timestamp';

    public const KIND_ALIASES = 'aliases';

    /**
     * Every field an op may set, in the record's order, with its column and kind.
     *
     * @var array<string, array{column: string|null, kind: string}>
     */
    public const SETTABLE = [
        'name' => ['column' => 'name', 'kind' => self::KIND_STRING],
        'aliases' => ['column' => null, 'kind' => self::KIND_ALIASES],
        'formerIds' => ['column' => 'former_ids', 'kind' => self::KIND_LIST],
        'logType' => ['column' => 'log_type', 'kind' => self::KIND_STRING],
        'equipment' => ['column' => 'equipment', 'kind' => self::KIND_LIST],
        'primaryMuscles' => ['column' => 'primary_muscles', 'kind' => self::KIND_LIST],
        'coreMuscles' => ['column' => 'core_muscles', 'kind' => self::KIND_LIST],
        'regionsLoaded' => ['column' => 'regions_loaded', 'kind' => self::KIND_MAP],
        'primaryMovements' => ['column' => 'primary_movements', 'kind' => self::KIND_LIST],
        'coreMovements' => ['column' => 'core_movements', 'kind' => self::KIND_LIST],
        'motion' => ['column' => 'motion', 'kind' => self::KIND_STRING],
        'modalities' => ['column' => 'modalities', 'kind' => self::KIND_LIST],
        'kinds' => ['column' => 'kinds', 'kind' => self::KIND_LIST],
        'laterality' => ['column' => 'laterality', 'kind' => self::KIND_STRING],
        'families' => ['column' => 'families', 'kind' => self::KIND_LIST],
        'category' => ['column' => 'category', 'kind' => self::KIND_STRING],
        'maxPerWeek' => ['column' => 'max_per_week', 'kind' => self::KIND_INTEGER],
        'repeatCooldown' => ['column' => 'repeat_cooldown', 'kind' => self::KIND_INTEGER],
        'suggestionCount' => ['column' => 'suggestion_count', 'kind' => self::KIND_INTEGER],
        'suggestionPeriod' => ['column' => 'suggestion_period', 'kind' => self::KIND_STRING],
        'video' => ['column' => 'video', 'kind' => self::KIND_STRING],
        'generatorEligible' => ['column' => 'generator_eligible', 'kind' => self::KIND_BOOLEAN],
        'historicReps' => ['column' => 'historic_reps', 'kind' => self::KIND_LIST],
        'modifier' => ['column' => 'modifier', 'kind' => self::KIND_STRING],
        'createdAt' => ['column' => 'created_at', 'kind' => self::KIND_TIMESTAMP],
        'members' => ['column' => 'members', 'kind' => self::KIND_LIST],
        'bucket' => ['column' => 'bucket', 'kind' => self::KIND_STRING],
        'tags' => ['column' => 'tags', 'kind' => self::KIND_LIST],
    ];

    /**
     * The export's keys after `canonical_name`, in the record's order: every column, with `aliases`
     * in its place.
     *
     * @return list<string>
     */
    public static function exportKeys(): array
    {
        return array_map(
            fn (array $field): string => $field['column'] ?? 'aliases',
            array_values(self::SETTABLE),
        );
    }
}
