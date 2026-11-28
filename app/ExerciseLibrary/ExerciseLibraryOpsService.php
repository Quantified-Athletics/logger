<?php

namespace App\ExerciseLibrary;

use App\Models\Exercise;
use App\Models\ExerciseAlias;
use App\Services\ExerciseMergeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Applies an exercise-library ops file (`{ "ops": [{ "op", "id", "set"?, "into"?, "reason" }] }`) to
 * Logger's standard exercises (`user_id` null, not soft-deleted). Every op is checked before anything
 * is written, and a refused file writes nothing. Every `add` and `update` applies in file order, then
 * every `merge`. A second run of the same file changes nothing. Each change is logged with the op's
 * `reason` as its activity description, next to the old values.
 */
class ExerciseLibraryOpsService
{
    private const OPS = ['add', 'update', 'merge'];

    public function __construct(
        private LogTypeRegistry $logTypes,
        private ExerciseMergeService $mergeService,
    ) {}

    /**
     * Read and decode an ops file.
     *
     * @return array<mixed>
     */
    public function readOpsFile(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new ExerciseLibraryOpsRefusedException(["The ops file {$path} does not exist or is unreadable."]);
        }

        $document = json_decode((string) file_get_contents($path), true);
        if (! is_array($document)) {
            throw new ExerciseLibraryOpsRefusedException(["The ops file {$path} is not a JSON object."]);
        }

        return $document;
    }

    /**
     * Apply the ops, or with `$check` prove they would apply and roll every write back.
     *
     * @param  array<mixed>  $document
     * @return array{added: int, updated: int, merged: int, already_merged: int, unchanged: int}
     */
    public function apply(array $document, bool $check = false): array
    {
        $ops = $this->opsFrom($document);

        $refusals = $this->refusals($ops);
        if ($refusals !== []) {
            throw new ExerciseLibraryOpsRefusedException($refusals);
        }

        DB::beginTransaction();

        try {
            $report = $this->applyOps($ops);
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        $check ? DB::rollBack() : DB::commit();

        return $report;
    }

    /**
     * @param  array<mixed>  $document
     * @return list<array<mixed>>
     */
    private function opsFrom(array $document): array
    {
        $ops = $document['ops'] ?? null;
        if (! is_array($ops) || ! array_is_list($ops)) {
            throw new ExerciseLibraryOpsRefusedException(['The ops file has no "ops" list.']);
        }

        foreach ($ops as $index => $op) {
            if (! is_array($op) || ($op !== [] && array_is_list($op))) {
                throw new ExerciseLibraryOpsRefusedException(["Op #{$index} is not an object."]);
            }
        }

        return $ops;
    }

    /**
     * Every reason the ops can't apply, checked against the database as it stands.
     *
     * @param  list<array<mixed>>  $ops
     * @return list<string>
     */
    private function refusals(array $ops): array
    {
        $refusals = [];
        $addedIds = [];
        $mergedIds = [];

        foreach ($ops as $op) {
            if (($op['op'] ?? null) === 'add' && is_string($op['id'] ?? null)) {
                $addedIds[$op['id']] = true;
            }
            if (($op['op'] ?? null) === 'merge' && is_string($op['id'] ?? null)) {
                $mergedIds[$op['id']] = true;
            }
        }

        foreach ($ops as $index => $op) {
            $id = $op['id'] ?? null;
            $label = is_string($id) ? "Op #{$index} ({$id})" : "Op #{$index}";
            $kind = $op['op'] ?? null;

            if (! in_array($kind, self::OPS, true)) {
                $refusals[] = "{$label}: unknown op ".json_encode($kind).'; an op is add, update or merge.';

                continue;
            }

            if (! is_string($id) || trim($id) === '') {
                $refusals[] = "{$label}: has no id.";

                continue;
            }

            if (! is_string($op['reason'] ?? null) || trim($op['reason']) === '') {
                $refusals[] = "{$label}: has no reason; every op needs one.";
            }

            if ($kind === 'merge') {
                array_push($refusals, ...$this->mergeRefusals($op, $label, $addedIds, $mergedIds));

                continue;
            }

            $set = $op['set'] ?? [];
            if (! is_array($set) || ($set !== [] && array_is_list($set))) {
                $refusals[] = "{$label}: set is not an object.";

                continue;
            }

            array_push($refusals, ...$this->setRefusals($set, $label));

            $rows = $this->standardRows($id);
            if ($rows > 1) {
                $refusals[] = "{$label}: more than one standard exercise has canonical_name {$id}.";
            }

            if ($kind === 'update' && $rows === 0 && ! isset($addedIds[$id])) {
                $refusals[] = "{$label}: no standard exercise has canonical_name {$id}.";
            }

            if ($kind === 'add' && $rows === 0) {
                foreach (['name', 'logType'] as $required) {
                    if (! isset($set[$required])) {
                        $refusals[] = "{$label}: an add needs {$required}.";
                    }
                }
            }
        }

        return $refusals;
    }

    /**
     * @param  array<mixed>  $op
     * @param  array<string, true>  $addedIds
     * @param  array<string, true>  $mergedIds
     * @return list<string>
     */
    private function mergeRefusals(array $op, string $label, array $addedIds, array $mergedIds): array
    {
        $id = $op['id'];
        $into = $op['into'] ?? null;

        if (array_key_exists('set', $op)) {
            return ["{$label}: a merge sets no fields."];
        }

        if (! is_string($into) || trim($into) === '') {
            return ["{$label}: a merge needs into."];
        }

        if ($into === $id) {
            return ["{$label}: cannot merge {$id} into itself."];
        }

        if (isset($mergedIds[$into])) {
            return ["{$label}: its target {$into} is itself merged away."];
        }

        $refusals = [];
        $targetRows = $this->standardRows($into);
        if ($targetRows > 1) {
            $refusals[] = "{$label}: more than one standard exercise has canonical_name {$into}.";
        }
        if ($targetRows === 0 && ! isset($addedIds[$into])) {
            $refusals[] = "{$label}: no standard exercise has canonical_name {$into} to merge into.";
        }

        $sourceRows = $this->standardRows($id);
        if ($sourceRows > 1) {
            $refusals[] = "{$label}: more than one standard exercise has canonical_name {$id}.";
        }
        if ($sourceRows === 0 && ! $this->alreadyMerged($id, $into)) {
            $refusals[] = "{$label}: no standard exercise has canonical_name {$id} to merge.";
        }

        return $refusals;
    }

    /**
     * @param  array<mixed>  $set
     * @return list<string>
     */
    private function setRefusals(array $set, string $label): array
    {
        $refusals = [];

        foreach ($set as $field => $value) {
            $definition = RecordFields::SETTABLE[$field] ?? null;
            if ($definition === null) {
                $refusals[] = "{$label}: {$field} is not a field an op can set.";

                continue;
            }

            if ($field === 'logType') {
                if (! is_string($value) || ! $this->logTypes->contains($value)) {
                    $refusals[] = "{$label}: log type ".json_encode($value).' is not in '.LogTypeRegistry::REGISTRY_PATH.'.';
                }

                continue;
            }

            if ($value === null) {
                if ($field === 'name') {
                    $refusals[] = "{$label}: name cannot be removed.";
                }

                continue;
            }

            if (! $this->valueFits($definition['kind'], $value)) {
                $refusals[] = "{$label}: {$field} holds ".json_encode($value).", not a {$definition['kind']}.";
            }
        }

        return $refusals;
    }

    private function valueFits(string $kind, mixed $value): bool
    {
        return match ($kind) {
            RecordFields::KIND_STRING => is_string($value) && $value !== '',
            RecordFields::KIND_INTEGER => is_int($value) && $value >= 0,
            RecordFields::KIND_BOOLEAN => is_bool($value),
            RecordFields::KIND_TIMESTAMP => is_string($value) && strtotime($value) !== false,
            RecordFields::KIND_ALIASES => $this->isListOfStrings($value),
            RecordFields::KIND_LIST => is_array($value) && array_is_list($value)
                && array_filter($value, fn (mixed $item): bool => ! $this->isListItem($item)) === [],
            RecordFields::KIND_MAP => is_array($value) && ($value === [] || ! array_is_list($value))
                && array_filter($value, fn (mixed $item): bool => $item !== null && ! is_string($item)) === [],
            default => false,
        };
    }

    /**
     * A list item is a value, or an either-or group of values (`[["band", "cable-machine"], "wall"]`).
     */
    private function isListItem(mixed $item): bool
    {
        return (is_string($item) && $item !== '') || ($this->isListOfStrings($item) && $item !== []);
    }

    private function isListOfStrings(mixed $value): bool
    {
        return is_array($value) && array_is_list($value)
            && array_filter($value, fn (mixed $item): bool => ! is_string($item) || $item === '') === [];
    }

    /**
     * @param  list<array<mixed>>  $ops
     * @return array{added: int, updated: int, merged: int, already_merged: int, unchanged: int}
     */
    private function applyOps(array $ops): array
    {
        $report = ['added' => 0, 'updated' => 0, 'merged' => 0, 'already_merged' => 0, 'unchanged' => 0];

        foreach ($ops as $op) {
            if ($op['op'] === 'merge') {
                continue;
            }

            $exercise = $this->standardRow($op['id']);
            $isNew = $exercise === null;
            $exercise ??= new Exercise(['canonical_name' => $op['id'], 'user_id' => null]);

            $changed = $this->applySet($exercise, $op['set'] ?? [], $op['reason']);
            $report[$isNew ? 'added' : ($changed ? 'updated' : 'unchanged')]++;
        }

        $setsAliases = [];
        foreach ($ops as $op) {
            if ($op['op'] !== 'merge' && array_key_exists('aliases', $op['set'] ?? [])) {
                $setsAliases[$op['id']] = true;
            }
        }

        foreach ($ops as $op) {
            if ($op['op'] !== 'merge') {
                continue;
            }

            $source = $this->standardRow($op['id']);
            $target = $this->standardRow($op['into']);
            if ($source === null) {
                $report['already_merged']++;

                continue;
            }

            $mergeLog = $this->mergeService->mergeStandardExercise($source, $target, $op['reason'], isset($setsAliases[$op['into']]));

            $formerIds = $target->former_ids ?? [];
            if (! in_array($source->canonical_name, $formerIds, true)) {
                $formerIds[] = $source->canonical_name;
            }
            $this->applySet($target, ['formerIds' => $formerIds], $op['reason']);

            activity()
                ->performedOn($target)
                ->event('merged')
                ->withProperties(['merged' => $source->canonical_name, 'exercise_merge_log_id' => $mergeLog->id])
                ->log($op['reason']);

            $report['merged']++;
        }

        return $report;
    }

    /**
     * Set the op's fields on the exercise and log what changed, old values included, under the reason.
     *
     * @param  array<string, mixed>  $set
     * @return bool whether anything changed
     */
    private function applySet(Exercise $exercise, array $set, string $reason): bool
    {
        $isNew = ! $exercise->exists;

        foreach ($set as $field => $value) {
            $definition = RecordFields::SETTABLE[$field];

            match ($definition['kind']) {
                RecordFields::KIND_ALIASES => null,
                RecordFields::KIND_TIMESTAMP => $value === null ? null : $exercise->setAttribute($definition['column'], Carbon::parse($value)),
                RecordFields::KIND_LIST, RecordFields::KIND_MAP => $exercise->setAttribute($definition['column'], $value === [] ? null : $value),
                default => $exercise->setAttribute($definition['column'], $value),
            };
        }

        $dirty = array_keys($exercise->getDirty());
        $old = [];
        $new = [];
        foreach ($dirty as $column) {
            $old[$column] = $isNew ? null : $exercise->getOriginal($column);
            $new[$column] = $exercise->getAttribute($column);
        }

        $exercise->disableLogging();
        $exercise->save();
        $exercise->enableLogging();

        if (array_key_exists('aliases', $set)) {
            $oldAliases = $this->standardAliasNames($exercise);
            $newAliases = $this->syncStandardAliases($exercise, $set['aliases'] ?? []);
            if ($oldAliases !== $newAliases) {
                $old['aliases'] = $oldAliases;
                $new['aliases'] = $newAliases;
            }
        }

        if ($new === [] && ! $isNew) {
            return false;
        }

        activity()
            ->performedOn($exercise)
            ->event($isNew ? 'created' : 'updated')
            ->withProperties($isNew ? ['attributes' => $new] : ['attributes' => $new, 'old' => $old])
            ->log($reason);

        return true;
    }

    /**
     * Make the exercise's standard aliases exactly the given names; user aliases stay.
     *
     * @param  list<string>  $aliasNames
     * @return list<string> the standard alias names after the sync
     */
    private function syncStandardAliases(Exercise $exercise, array $aliasNames): array
    {
        $wanted = array_values(array_unique($aliasNames));

        $existing = ExerciseAlias::query()->where('exercise_id', $exercise->id)->whereNull('user_id')->get();
        foreach ($existing as $alias) {
            if (! in_array($alias->alias_name, $wanted, true)) {
                $alias->delete();
            }
        }

        $kept = $existing->pluck('alias_name')->all();
        foreach ($wanted as $aliasName) {
            if (! in_array($aliasName, $kept, true)) {
                ExerciseAlias::create(['user_id' => null, 'exercise_id' => $exercise->id, 'alias_name' => $aliasName]);
            }
        }

        return $this->standardAliasNames($exercise);
    }

    /**
     * @return list<string>
     */
    private function standardAliasNames(Exercise $exercise): array
    {
        return ExerciseAlias::query()
            ->where('exercise_id', $exercise->id)
            ->whereNull('user_id')
            ->orderBy('alias_name')
            ->pluck('alias_name')
            ->all();
    }

    private function alreadyMerged(string $id, string $into): bool
    {
        $target = $this->standardRow($into);

        return $target !== null && in_array($id, $target->former_ids ?? [], true);
    }

    private function standardRows(string $canonicalName): int
    {
        return Exercise::query()->global()->where('canonical_name', $canonicalName)->count();
    }

    private function standardRow(string $canonicalName): ?Exercise
    {
        return Exercise::query()->global()->where('canonical_name', $canonicalName)->first();
    }
}
