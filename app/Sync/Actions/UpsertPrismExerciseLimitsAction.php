<?php

namespace App\Sync\Actions;

use App\Sync\Models\PrismExerciseCap;
use App\Sync\Models\PrismExerciseFamily;
use App\Sync\Models\PrismExerciseFamilyMember;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class UpsertPrismExerciseLimitsAction
{
    private const REMOVAL_UPDATE_COLUMNS = ['device_id', 'deleted_at', 'updated_at'];

    /**
     * Upsert and remove exercise families, family members and exercise caps in one transaction. Every touched
     * row shares the returned timestamp as its updated_at (and deleted_at for removals).
     *
     * @param  array{upserts: array<int, array{family_id: string, name: string, cap_count: ?int, cap_period: ?string}>, removals: array<int, array{family_id: string}>}  $families
     * @param  array{upserts: array<int, array{family_id: string, exercise_id: string, is_member: bool}>, removals: array<int, array{family_id: string, exercise_id: string}>}  $members
     * @param  array{upserts: array<int, array{exercise_id: string, cap_count: ?int, cap_period: ?string}>, removals: array<int, array{exercise_id: string}>}  $caps
     */
    public function execute(string $gymId, array $families, array $members, array $caps, ?string $deviceId): Carbon
    {
        $now = Carbon::now();
        $timestamp = $now->format('Y-m-d H:i:s.u');

        DB::transaction(function () use ($gymId, $families, $members, $caps, $deviceId, $timestamp): void {
            $this->writeFamilies($gymId, $families, $deviceId, $timestamp);
            $this->writeMembers($gymId, $members, $deviceId, $timestamp);
            $this->writeCaps($gymId, $caps, $deviceId, $timestamp);
        });

        return $now;
    }

    /**
     * @param  array{upserts: array<int, array<string, mixed>>, removals: array<int, array<string, mixed>>}  $group
     */
    private function writeFamilies(string $gymId, array $group, ?string $deviceId, string $timestamp): void
    {
        PrismExerciseFamily::upsert(
            array_map(fn (array $row): array => [
                'gym_id' => $gymId,
                'family_id' => (string) $row['family_id'],
                'name' => $row['name'],
                'cap_count' => $row['cap_count'],
                'cap_period' => $row['cap_period'],
                'device_id' => $deviceId,
                'deleted_at' => null,
                'updated_at' => $timestamp,
            ], $group['upserts']),
            ['gym_id', 'family_id'],
            ['name', 'cap_count', 'cap_period', 'device_id', 'deleted_at', 'updated_at'],
        );

        PrismExerciseFamily::upsert(
            array_map(fn (array $row): array => [
                'gym_id' => $gymId,
                'family_id' => (string) $row['family_id'],
                'name' => '',
                'cap_count' => null,
                'cap_period' => null,
                'device_id' => $deviceId,
                'deleted_at' => $timestamp,
                'updated_at' => $timestamp,
            ], $group['removals']),
            ['gym_id', 'family_id'],
            self::REMOVAL_UPDATE_COLUMNS,
        );
    }

    /**
     * @param  array{upserts: array<int, array<string, mixed>>, removals: array<int, array<string, mixed>>}  $group
     */
    private function writeMembers(string $gymId, array $group, ?string $deviceId, string $timestamp): void
    {
        PrismExerciseFamilyMember::upsert(
            array_map(fn (array $row): array => [
                'gym_id' => $gymId,
                'exercise_id' => (string) $row['exercise_id'],
                'family_id' => (string) $row['family_id'],
                'is_member' => $row['is_member'],
                'device_id' => $deviceId,
                'deleted_at' => null,
                'updated_at' => $timestamp,
            ], $group['upserts']),
            ['gym_id', 'exercise_id', 'family_id'],
            ['is_member', 'device_id', 'deleted_at', 'updated_at'],
        );

        PrismExerciseFamilyMember::upsert(
            array_map(fn (array $row): array => [
                'gym_id' => $gymId,
                'exercise_id' => (string) $row['exercise_id'],
                'family_id' => (string) $row['family_id'],
                'is_member' => false,
                'device_id' => $deviceId,
                'deleted_at' => $timestamp,
                'updated_at' => $timestamp,
            ], $group['removals']),
            ['gym_id', 'exercise_id', 'family_id'],
            self::REMOVAL_UPDATE_COLUMNS,
        );
    }

    /**
     * @param  array{upserts: array<int, array<string, mixed>>, removals: array<int, array<string, mixed>>}  $group
     */
    private function writeCaps(string $gymId, array $group, ?string $deviceId, string $timestamp): void
    {
        PrismExerciseCap::upsert(
            array_map(fn (array $row): array => [
                'gym_id' => $gymId,
                'exercise_id' => (string) $row['exercise_id'],
                'cap_count' => $row['cap_count'],
                'cap_period' => $row['cap_period'],
                'device_id' => $deviceId,
                'deleted_at' => null,
                'updated_at' => $timestamp,
            ], $group['upserts']),
            ['gym_id', 'exercise_id'],
            ['cap_count', 'cap_period', 'device_id', 'deleted_at', 'updated_at'],
        );

        PrismExerciseCap::upsert(
            array_map(fn (array $row): array => [
                'gym_id' => $gymId,
                'exercise_id' => (string) $row['exercise_id'],
                'cap_count' => null,
                'cap_period' => null,
                'device_id' => $deviceId,
                'deleted_at' => $timestamp,
                'updated_at' => $timestamp,
            ], $group['removals']),
            ['gym_id', 'exercise_id'],
            self::REMOVAL_UPDATE_COLUMNS,
        );
    }
}
