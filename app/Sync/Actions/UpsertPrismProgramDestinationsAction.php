<?php

namespace App\Sync\Actions;

use App\Sync\Models\PrismProgramDestination;
use Illuminate\Support\Carbon;

class UpsertPrismProgramDestinationsAction
{
    /** Fields that identify what a destination publishes to; a change to any re-stamps attribution. */
    private const TARGET_FIELDS = [
        'spreadsheet_id',
        'sheet_id',
        'momence_track',
        'momence_cycle_prefix',
        'momence_bridge_prefix',
        'momence_bridge_weeks',
    ];

    /**
     * Link (upsert) and unlink (tombstone) a program's publish destinations.
     *
     * A link is its own row, so it can never clobber — or be clobbered by — a program-level
     * write. Re-linking the same type replaces the previous link and clears any tombstone.
     *
     * @param  array<int, array{type: string, spreadsheet_id?: string, sheet_id?: int, title?: ?string, momence_track?: string, momence_cycle_prefix?: string, momence_bridge_prefix?: string, momence_bridge_weeks?: int}>  $upserts
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

            $fields = $this->fieldsFor($item);

            // Re-sending the same live link (e.g. after another coach's Picker grant or a re-export)
            // must not relabel who linked it. Only a new target, or re-linking after an unlink,
            // re-stamps the attribution.
            $sameLiveLink = $destination->exists
                && $destination->deleted_at === null
                && $this->targetMatches($destination, $fields);

            if (! $sameLiveLink) {
                $destination->linked_by_user_id = $userId;
                $destination->linked_at = $destination->freshTimestamp();
            }

            $destination->fill([
                ...$fields,
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

    /**
     * The columns an upsert writes. Each type stores only its own fields; the other type's are null.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function fieldsFor(array $item): array
    {
        $isMomence = $item['type'] === PrismProgramDestination::TYPE_MOMENCE;
        $own = fn (string $key, bool $belongs): mixed => $belongs ? ($item[$key] ?? null) : null;

        return [
            'spreadsheet_id' => $own('spreadsheet_id', ! $isMomence),
            'sheet_id' => $own('sheet_id', ! $isMomence),
            'title' => $own('title', ! $isMomence),
            'momence_track' => $own('momence_track', $isMomence),
            'momence_cycle_prefix' => $own('momence_cycle_prefix', $isMomence),
            'momence_bridge_prefix' => $own('momence_bridge_prefix', $isMomence),
            'momence_bridge_weeks' => $own('momence_bridge_weeks', $isMomence),
        ];
    }

    /**
     * Whether the stored link already points at the same target. A Sheets title is display-only,
     * so it is left out; every Momence field is part of the target.
     *
     * @param  array<string, mixed>  $fields
     */
    private function targetMatches(PrismProgramDestination $destination, array $fields): bool
    {
        foreach (self::TARGET_FIELDS as $field) {
            if ($destination->{$field} !== $fields[$field]) {
                return false;
            }
        }

        return true;
    }

    private function later(?Carbon $a, Carbon $b): Carbon
    {
        return $a === null || $b->gt($a) ? $b : $a;
    }
}
