<?php

namespace App\ExerciseLibrary;

use App\Models\Exercise;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Reads the shared log type registry, Logger's byte-equal copy at `database/data/cue/log_types.json`.
 * A standard exercise's `log_type` is only one this registry lists.
 */
class LogTypeRegistry
{
    public const REGISTRY_PATH = 'database/data/cue/log_types.json';

    /**
     * @var list<string>|null
     */
    private ?array $ids = null;

    /**
     * Every log type id the registry lists, in its order.
     *
     * @return list<string>
     */
    public function ids(): array
    {
        if ($this->ids !== null) {
            return $this->ids;
        }

        $registry = json_decode((string) file_get_contents(base_path(self::REGISTRY_PATH)), true);
        if (! is_array($registry) || ! is_array($registry['logTypes'] ?? null)) {
            throw new RuntimeException('The log type registry at '.self::REGISTRY_PATH.' is unreadable.');
        }

        return $this->ids = array_values(array_map(
            fn (array $logType): string => (string) $logType['id'],
            $registry['logTypes'],
        ));
    }

    public function contains(?string $logType): bool
    {
        return $logType !== null && in_array($logType, $this->ids(), true);
    }

    /**
     * The standard exercises (no user, not soft-deleted) whose log type the registry does not list.
     *
     * @return Collection<string, string|null> log type keyed by canonical name
     */
    public function standardExercisesOutside(): Collection
    {
        return Exercise::query()
            ->global()
            ->where(fn ($query) => $query->whereNull('log_type')->orWhereNotIn('log_type', $this->ids()))
            ->orderBy('canonical_name')
            ->pluck('log_type', 'canonical_name');
    }
}
