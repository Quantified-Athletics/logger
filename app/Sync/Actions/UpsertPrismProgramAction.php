<?php

namespace App\Sync\Actions;

use App\Sync\Models\PrismProgram;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class UpsertPrismProgramAction
{
    /** Program fields a client may write. Absent ⇒ untouched; present (incl. null) ⇒ overwrite. */
    private const WRITABLE_FIELDS = ['name', 'week_count', 'start_date', 'archived_at'];

    /**
     * Partially create or update a Prism program.
     *
     * Each field is written independently, so concurrent edits to different fields by
     * different coaches all survive (per-field last-write-wins).
     *
     * @param  array<string, mixed>  $attributes  validated request fields plus optional device_id
     *
     * @throws ValidationException when creating without name or week_count
     */
    public function execute(string $gymId, string $programUuid, array $attributes): Carbon
    {
        $fields = array_intersect_key($attributes, array_flip(self::WRITABLE_FIELDS));
        $deviceId = $attributes['device_id'] ?? null;

        $program = $this->find($gymId, $programUuid);

        if ($program === null) {
            if (! array_key_exists('name', $fields) || ! array_key_exists('week_count', $fields)) {
                throw ValidationException::withMessages([
                    'name' => 'The name and week count fields are required when creating a program.',
                ]);
            }

            try {
                return PrismProgram::create([
                    'gym_id' => $gymId,
                    'program_uuid' => $programUuid,
                    'device_id' => $deviceId,
                ] + $fields)->updated_at;
            } catch (UniqueConstraintViolationException) {
                // Another coach created the same program at the same moment: apply as an update.
                $program = $this->find($gymId, $programUuid)
                    ?? throw new \RuntimeException("Prism program {$programUuid} vanished after a unique violation.");
            }
        }

        $program->fill($fields);
        $program->device_id = $deviceId;
        // Always bump, so every accepted write (even an identical repeat) appears in /changes.
        $program->updated_at = $program->freshTimestamp();
        $program->save();

        return $program->updated_at;
    }

    private function find(string $gymId, string $programUuid): ?PrismProgram
    {
        return PrismProgram::where('gym_id', $gymId)->where('program_uuid', $programUuid)->first();
    }
}
