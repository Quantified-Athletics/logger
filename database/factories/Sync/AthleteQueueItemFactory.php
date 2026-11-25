<?php

namespace Database\Factories\Sync;

use App\Models\User;
use App\Sync\Models\AthleteQueueItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AthleteQueueItem>
 */
class AthleteQueueItemFactory extends Factory
{
    protected $model = AthleteQueueItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $itemId = 'q-'.Str::uuid();
        $updatedAt = CarbonImmutable::now('UTC')->startOfSecond();

        return [
            'user_id' => User::factory(),
            'item_id' => $itemId,
            'item' => json_encode([
                'id' => $itemId,
                'updatedAt' => $updatedAt->format('Y-m-d\TH:i:s.v\Z'),
                'source' => 'generated',
                'blocks' => [
                    ['type' => 'strength', 'exercises' => [['name' => 'Back Squat', 'sets' => 5, 'reps' => 5]]],
                ],
                'meta' => ['title' => fake()->words(2, true)],
                'generatorConfig' => ['focus' => 'lower'],
            ]),
            'item_updated_at' => $updatedAt,
            'deleted_at' => null,
        ];
    }

    public function tombstoned(): static
    {
        return $this->state(fn (): array => ['deleted_at' => now()]);
    }
}
