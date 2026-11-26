<?php

namespace App\Services\MenuBadges;

class FeedBadgeCounter implements MenuBadgeCounter
{
    public function __construct(
        private UnreadPrFeedBadgeCounter $unreadPrFeedBadgeCounter,
        private UnreadNotificationsBadgeCounter $unreadNotificationsBadgeCounter,
    ) {
    }

    /**
     * Count the unread PR feed groups plus the unread notifications, for the top-level Feed item.
     */
    public function count(): int
    {
        return $this->unreadPrFeedBadgeCounter->count() + $this->unreadNotificationsBadgeCounter->count();
    }
}
