<?php

namespace App\Sync\Actions;

use App\Sync\Models\PrismProgramComment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class UpsertPrismCommentsAction
{
    /**
     * Create, edit, restore and delete a program's comments. A new uuid is created with the signed-in user as
     * author. An existing uuid (an edit, or an undo restoring a deleted comment) and a removal are allowed
     * only for the comment's author; if any row is someone else's, nothing is written and null is returned.
     *
     * @param  array<int, array{uuid: string, workout_uuid: string, body: string}>  $upserts
     * @param  array<int, array{uuid: string}>  $removals
     */
    public function execute(string $gymId, string $programUuid, array $upserts, array $removals, int $userId, ?string $deviceId): ?Carbon
    {
        $uuids = array_merge(array_column($upserts, 'uuid'), array_column($removals, 'uuid'));
        $existing = PrismProgramComment::query()
            ->where('gym_id', $gymId)
            ->whereIn('uuid', $uuids)
            ->get()
            ->keyBy('uuid');

        foreach ($existing as $comment) {
            if ((int) $comment->author_user_id !== $userId) {
                return null;
            }
        }

        $now = Carbon::now();

        DB::transaction(function () use ($gymId, $programUuid, $upserts, $removals, $userId, $deviceId, $existing, $now): void {
            foreach ($upserts as $row) {
                $comment = $existing->get($row['uuid']) ?? new PrismProgramComment([
                    'gym_id' => $gymId,
                    'uuid' => $row['uuid'],
                    'author_user_id' => $userId,
                ]);
                $comment->fill([
                    'program_uuid' => $programUuid,
                    'workout_uuid' => $row['workout_uuid'],
                    'body' => $row['body'],
                    'device_id' => $deviceId,
                    'deleted_at' => null,
                ]);
                $comment->updated_at = $now;
                $comment->save();
            }

            foreach ($removals as $row) {
                $comment = $existing->get($row['uuid']);
                if ($comment === null || $comment->deleted_at !== null) {
                    continue;
                }
                $comment->fill(['device_id' => $deviceId, 'deleted_at' => $now]);
                $comment->updated_at = $now;
                $comment->save();
            }
        });

        return $now;
    }
}
