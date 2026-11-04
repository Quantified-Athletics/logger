<?php

namespace App\Sync\Actions;

use App\Sync\Models\PrismProgramCell;
use Illuminate\Support\Carbon;

class UpsertPrismCellsAction
{
    /**
     * Upsert a batch of Prism program cells, tombstone removals, and return the
     * max of updated_at/deleted_at across the touched rows.
     *
     * An upsert clears any existing tombstone (deleted_at => null), so a
     * re-created cell un-tombstones. A removal flips deleted_at non-null while
     * keeping the row (and its cell_id) for the unique key.
     *
     * A cell that carries a `workout_uuid` stores it; a cell without one (an older client) leaves the
     * stored uuid alone. A removal clears it: the workout at that position is gone.
     *
     * @param  array<int, array{cell_id: string, text: string, workout_uuid?: string|null}>  $cells
     * @param  array<int, array{cell_id: string}>  $removals
     */
    public function execute(string $gymId, string $programUuid, array $cells, array $removals, ?string $deviceId): Carbon
    {
        if (empty($cells) && empty($removals)) {
            return Carbon::now();
        }

        $touchedCellIds = [];
        $now = Carbon::now();

        if (! empty($cells)) {
            // Upsert updates a fixed column list per call, so cells with and without a workout_uuid go in
            // separate batches: only the first may overwrite the stored uuid.
            $withUuid = [];
            $withoutUuid = [];
            foreach ($cells as $cell) {
                $cellId = (string) $cell['cell_id'];
                $touchedCellIds[] = $cellId;
                $row = [
                    'gym_id' => $gymId,
                    'program_uuid' => $programUuid,
                    'cell_id' => $cellId,
                    'text' => $cell['text'],
                    'device_id' => $deviceId,
                    'deleted_at' => null,
                ];
                if (! empty($cell['workout_uuid'])) {
                    $withUuid[] = $row + ['workout_uuid' => (string) $cell['workout_uuid']];
                } else {
                    $withoutUuid[] = $row;
                }
            }

            if (! empty($withUuid)) {
                PrismProgramCell::upsert(
                    $withUuid,
                    ['gym_id', 'program_uuid', 'cell_id'],
                    ['text', 'workout_uuid', 'device_id', 'deleted_at']
                );
            }

            if (! empty($withoutUuid)) {
                PrismProgramCell::upsert(
                    $withoutUuid,
                    ['gym_id', 'program_uuid', 'cell_id'],
                    ['text', 'device_id', 'deleted_at']
                );
            }
        }

        if (! empty($removals)) {
            $removalRows = [];
            $nowFormatted = $now->format('Y-m-d H:i:s.u');
            foreach ($removals as $removal) {
                $cellId = (string) $removal['cell_id'];
                $touchedCellIds[] = $cellId;
                $removalRows[] = [
                    'gym_id' => $gymId,
                    'program_uuid' => $programUuid,
                    'cell_id' => $cellId,
                    'text' => '',
                    'workout_uuid' => null,
                    'device_id' => $deviceId,
                    'deleted_at' => $nowFormatted,
                ];
            }

            PrismProgramCell::upsert(
                $removalRows,
                ['gym_id', 'program_uuid', 'cell_id'],
                ['text', 'workout_uuid', 'device_id', 'deleted_at']
            );
        }

        $maxUpdatedAt = PrismProgramCell::query()
            ->where('gym_id', $gymId)
            ->where('program_uuid', $programUuid)
            ->whereIn('cell_id', array_unique($touchedCellIds))
            ->max('updated_at');

        $maxDeletedAt = PrismProgramCell::query()
            ->where('gym_id', $gymId)
            ->where('program_uuid', $programUuid)
            ->whereIn('cell_id', array_unique($touchedCellIds))
            ->max('deleted_at');

        $timestamps = array_filter([$maxUpdatedAt, $maxDeletedAt]);

        if (empty($timestamps)) {
            return $now;
        }

        $carbonTimestamps = array_map(fn ($ts) => Carbon::parse($ts), $timestamps);

        return max($carbonTimestamps);
    }
}
