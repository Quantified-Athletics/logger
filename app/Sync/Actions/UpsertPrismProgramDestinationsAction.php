<?php

namespace App\Sync\Actions;

use App\Sync\Models\PrismProgramDestination;
use Illuminate\Support\Carbon;

class UpsertPrismProgramDestinationsAction
{
    /**
     * Link (upsert) and unlink (tombstone) a program's publish destinations.
     *
     * A link is its own row, so it can never clobber — or be clobbered by — a program-level
     * write. Re-linking the same type replaces the previous link and clears any tombstone.
     *
     * @param  array<int, array{type: string, spreadsheet_id?: string, sheet_id?: int, title?: ?string}>  $upserts
     * @param  array<int, array{type: string}>  $removals
     */
    public function execute(
        string $gymId,
        string $programUuid,
        array $upserts,
        array $removals,
        ?int $userId,
        ?string $deviceId,
    ): Carbon {
        $latest = null;

        foreach ($upserts as $item) {
            $destination = PrismProgramDestination::firstOrNew([
                'gym_id' => $gymId,
                'program_uuid' => $programUuid,
                'type' => $item['type'],
            ]);

            $destination->fill([
                'spreadsheet_id' => $item['spreadsheet_id'] ?? null,
                'sheet_id' => $item['sheet_id'] ?? null,
                'title' => $item['title'] ?? null,
                'linked_by_user_id' => $userId,
                'linked_at' => $destination->freshTimestamp(),
                'device_id' => $deviceId,
                'deleted_at' => null,
            ]);
            $destination->save();

            $latest = $this->later($latest, $destination->updated_at);
        }

        foreach ($removals as $item) {
            $destination = PrismProgramDestination::where('gym_id', $gymId)
                ->where('program_uuid', $programUuid)
                ->where('type', $item['type'])
                ->whereNull('deleted_at')
                ->first();

            if ($destination === null) {
                continue; // nothing linked (or already unlinked): no-op
            }

            $destination->deleted_at = $destination->freshTimestamp();
            $destination->device_id = $deviceId;
            $destination->save();

            $latest = $this->later($latest, $destination->updated_at);
        }

        return $latest ?? Carbon::now();
    }

    private function later(?Carbon $a, Carbon $b): Carbon
    {
        return $a === null || $b->gt($a) ? $b : $a;
    }
}
