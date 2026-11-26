<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\LiftLog;
use App\Models\Notification;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Services\MenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class MenuBadgeCountTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $followedUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->followedUser = User::factory()->create();
        $this->user->follow($this->followedUser);
    }

    /** @test */
    public function the_feed_menu_badges_count_unread_pr_groups_and_unread_notifications(): void
    {
        $feedExercise = Exercise::factory()->create(['user_id' => null, 'show_in_feed' => true]);
        $hiddenExercise = Exercise::factory()->create(['user_id' => null, 'show_in_feed' => false]);
        $stranger = User::factory()->create();

        $todayA = $this->createPr($this->followedUser, $feedExercise, now()->subHours(1));
        $todayB = $this->createPr($this->followedUser, $feedExercise, now()->subHours(1));
        $this->createPr($this->followedUser, $feedExercise, now()->subHours(1));
        $this->createPr($this->followedUser, $feedExercise, now()->subDays(2));
        $superseded = $this->createPr($this->followedUser, $feedExercise, now()->subDays(4));
        $todayB->update(['previous_pr_id' => $superseded->id]);
        $this->createPr($this->user, $feedExercise, now()->subDays(3));
        $readGroup = $this->createPr($this->user, $feedExercise, now()->subDays(5));
        $this->createPr($this->followedUser, $feedExercise, now()->subDays(10));
        $this->createPr($this->followedUser, $hiddenExercise, now()->subDays(1));
        $this->createPr($stranger, $feedExercise, now()->subDays(1));

        $this->user->readPersonalRecords()->attach($todayA->id, ['read_at' => now()]);
        $this->user->readPersonalRecords()->attach($readGroup->id, ['read_at' => now()]);

        $this->createNotification($this->user, null);
        $this->createNotification($this->user, null);
        $this->createNotification($this->user, now());
        $this->createNotification($this->followedUser, null);

        $this->actingAs($this->user);

        [$feed, $prs, $notifications] = $this->feedBadgeCounts();

        $this->assertSame(3, $prs);
        $this->assertSame(2, $notifications);
        $this->assertSame(5, $feed);
    }

    /** @test */
    public function the_feed_menu_badges_are_zero_for_a_guest(): void
    {
        $feedExercise = Exercise::factory()->create(['user_id' => null, 'show_in_feed' => true]);
        $this->createPr($this->followedUser, $feedExercise, now()->subHours(1));
        $this->createNotification($this->user, null);

        $this->assertSame([0, 0, 0], $this->feedBadgeCounts());
    }

    /** @test */
    public function an_unknown_badge_key_shows_no_badge(): void
    {
        Config::set('menu.main', [
            ['label' => 'Unknown', 'route' => 'feed.index', 'badge' => 'no-such-counter'],
        ]);

        $menu = app(MenuService::class)->getMainMenu();

        $this->assertCount(1, $menu);
        $this->assertArrayNotHasKey('badgeCount', $menu[0]);
    }

    /**
     * @return array{0: int, 1: int, 2: int} Feed, PRs and Notifications badge counts
     */
    private function feedBadgeCounts(): array
    {
        $feedItem = collect(app(MenuService::class)->getMainMenu())->firstWhere('id', 'feed-nav-link');
        $children = collect($feedItem['children']);

        return [
            $feedItem['badgeCount'],
            $children->firstWhere('label', 'PRs')['badgeCount'],
            $children->firstWhere('label', 'Notifications')['badgeCount'],
        ];
    }

    private function createPr(User $user, Exercise $exercise, \DateTimeInterface $achievedAt): PersonalRecord
    {
        $liftLog = LiftLog::factory()->create(['user_id' => $user->id, 'exercise_id' => $exercise->id]);

        return PersonalRecord::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'lift_log_id' => $liftLog->id,
            'achieved_at' => $achievedAt,
        ]);
    }

    private function createNotification(User $user, ?\DateTimeInterface $readAt): Notification
    {
        return Notification::create([
            'user_id' => $user->id,
            'type' => 'pr_comment',
            'actor_id' => $this->followedUser->id,
            'notifiable_type' => 'App\Models\PRComment',
            'notifiable_id' => 1,
            'data' => [],
            'read_at' => $readAt,
        ]);
    }
}
