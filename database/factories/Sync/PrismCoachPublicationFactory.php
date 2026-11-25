<?php

namespace Database\Factories\Sync;

use App\Models\User;
use App\Sync\Models\Gym;
use App\Sync\Models\PrismCoachPublication;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PrismCoachPublication>
 */
class PrismCoachPublicationFactory extends Factory
{
    protected $model = PrismCoachPublication::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $programUuid = (string) Str::uuid();
        $startDate = '2026-11-30';
        $publishedAt = now();

        return [
            'gym_id' => fn (): string => Gym::query()->firstOrCreate(
                ['id' => 'the-cove'],
                ['name' => 'The Cove'],
            )->id,
            'program_uuid' => $programUuid,
            'recipient_user_id' => User::factory(),
            'start_date' => $startDate,
            'raw' => json_encode([
                'metadata' => [
                    'source' => 'prism',
                    'program_uuid' => $programUuid,
                    'program_name' => fake()->words(2, true),
                    'track' => null,
                    'start_date' => $startDate,
                    'published_at' => $publishedAt->toIso8601String(),
                ],
                'workouts' => [
                    [
                        'id' => 'w1d1',
                        'week' => 1,
                        'day' => 1,
                        'dateLabel' => 'Mon 30 Nov',
                        'isRest' => false,
                        'text' => ['Back Squat 5x5'],
                        '_formatting' => ['formattedText' => ['**Back Squat** 5x5']],
                    ],
                ],
            ]),
            'published_by_user_id' => User::factory(),
            'published_at' => $publishedAt,
            'revoked_at' => null,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => ['revoked_at' => now()]);
    }
}
