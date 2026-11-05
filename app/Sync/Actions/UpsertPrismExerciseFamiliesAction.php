<?php

namespace App\Sync\Actions;

use App\Sync\Models\PrismExerciseFamily;
use Illuminate\Support\Carbon;

class UpsertPrismExerciseFamiliesAction
{
    /**
     * Upsert exercise families and write tombstones for removals.
     *
     * @param  array<int, array{family_id: string, record: array<string, mixed>}>  $upserts
     * @param  array<int, array{family_id: string}>  $removals
     */
    public function execute(string $gymId, array $upserts, array $removals, ?string $deviceId): Carbon
    {
        if (empty($upserts) && empty($removals)) {
            return Carbon::now();
        }

        $touchedFamilyIds = [];
        $now = Carbon::now();

        if (! empty($upserts)) {
            $upsertRows = [];
            foreach ($upserts as $item) {
                $familyId = (string) $item['family_id'];
                $touchedFamilyIds[] = $familyId;
                $upsertRows[] = [
                    'gym_id' => $gymId,
                    'family_id' => $familyId,
                    'record' => json_encode($item['record']),
                    'device_id' => $deviceId,
                    'deleted_at' => null,
                ];
            }

            PrismExerciseFamily::upsert(
                $upsertRows,
                ['gym_id', 'family_id'],
                ['record', 'device_id', 'deleted_at']
            );
        }

        if (! empty($removals)) {
            $removalRows = [];
            $nowFormatted = $now->format('Y-m-d H:i:s.u');
            foreach ($removals as $item) {
                $familyId = (string) $item['family_id'];
                $touchedFamilyIds[] = $familyId;
                $removalRows[] = [
                    'gym_id' => $gymId,
                    'family_id' => $familyId,
                    'record' => null,
                    'device_id' => $deviceId,
                    'deleted_at' => $nowFormatted,
                ];
            }

            PrismExerciseFamily::upsert(
                $removalRows,
                ['gym_id', 'family_id'],
                ['record', 'device_id', 'deleted_at']
            );
        }

        $maxUpdatedAt = PrismExerciseFamily::query()
            ->where('gym_id', $gymId)
            ->whereIn('family_id', array_unique($touchedFamilyIds))
            ->max('updated_at');

        $maxDeletedAt = PrismExerciseFamily::query()
            ->where('gym_id', $gymId)
            ->whereIn('family_id', array_unique($touchedFamilyIds))
            ->max('deleted_at');

        $timestamps = array_filter([$maxUpdatedAt, $maxDeletedAt]);

        if (empty($timestamps)) {
            return $now;
        }

        $carbonTimestamps = array_map(fn ($ts) => Carbon::parse($ts), $timestamps);

        return max($carbonTimestamps);
    }
}
