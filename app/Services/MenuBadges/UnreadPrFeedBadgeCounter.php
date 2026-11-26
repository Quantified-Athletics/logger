<?php

namespace App\Services\MenuBadges;

use App\Models\PersonalRecord;

class UnreadPrFeedBadgeCounter implements MenuBadgeCounter
{
    /**
     * Count user-date groups with at least one unread PR from the last 7 days, by people the
     * user follows plus the user, on exercises shown in the feed.
     */
    public function count(): int
    {
        $currentUser = auth()->user();
        if (!$currentUser) {
            return 0;
        }

        $followingIds = $currentUser->following()->pluck('users.id')->toArray();
        $followingIds[] = $currentUser->id;

        $prs = PersonalRecord::current()
            ->where('achieved_at', '>=', now()->subDays(7))
            ->whereIn('user_id', $followingIds)
            ->whereHas('exercise', function ($query) {
                $query->where('show_in_feed', true);
            })
            ->get();

        $readPRIds = $currentUser->readPersonalRecords()->pluck('personal_records.id')->toArray();

        $newPRCount = 0;
        $seenGroups = [];

        foreach ($prs as $pr) {
            $groupKey = $pr->user_id . '_' . $pr->achieved_at->format('Y-m-d');

            if (isset($seenGroups[$groupKey])) {
                continue;
            }

            if (!in_array($pr->id, $readPRIds)) {
                $newPRCount++;
                $seenGroups[$groupKey] = true;
            }
        }

        return $newPRCount;
    }
}
