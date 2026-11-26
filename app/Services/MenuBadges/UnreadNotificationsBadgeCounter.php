<?php

namespace App\Services\MenuBadges;

class UnreadNotificationsBadgeCounter implements MenuBadgeCounter
{
    /**
     * Count the signed-in user's unread notifications.
     */
    public function count(): int
    {
        $currentUser = auth()->user();
        if (!$currentUser) {
            return 0;
        }

        return $currentUser->notifications()->unread()->count();
    }
}
