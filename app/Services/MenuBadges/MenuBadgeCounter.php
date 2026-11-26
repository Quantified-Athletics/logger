<?php

namespace App\Services\MenuBadges;

interface MenuBadgeCounter
{
    /**
     * Count what the menu badge shows for the signed-in user, 0 for a guest.
     */
    public function count(): int;
}
