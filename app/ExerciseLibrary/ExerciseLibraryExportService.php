<?php

namespace App\ExerciseLibrary;

use App\Models\Exercise;
use App\Models\ExerciseAlias;

/**
 * The standard exercises (`user_id` null, not soft-deleted) in the shape the root's contracts read:
 * one object per row with `canonical_name` and every record field under its snake_case column, lists
 * as arrays, absent values as `null`, and `aliases` as the row's standard aliases.
 */
class ExerciseLibraryExportService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function standardExercises(): array
    {
        return Exercise::query()
            ->global()
            ->with(['aliases' => fn ($query) => $query->whereNull('user_id')->orderBy('alias_name')])
            ->orderBy('canonical_name')
            ->get()
            ->map(fn (Exercise $exercise): array => $this->row($exercise))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Exercise $exercise): array
    {
        $row = ['canonical_name' => $exercise->canonical_name];

        foreach (RecordFields::SETTABLE as $definition) {
            $column = $definition['column'];

            $row[$column ?? 'aliases'] = match ($definition['kind']) {
                RecordFields::KIND_ALIASES => $exercise->aliases->map(fn (ExerciseAlias $alias): string => $alias->alias_name)->values()->all(),
                RecordFields::KIND_TIMESTAMP => $exercise->created_at?->copy()->utc()->format('Y-m-d\TH:i:s.v\Z'),
                default => $exercise->getAttribute($column),
            };
        }

        return $row;
    }
}
