<?php

namespace App\Support\Group;

use App\Enums\GameStatus;
use App\Models\Group;

final class GroupScheduleCompletion
{
    public static function expectedPairCount(int $entryCount): int
    {
        if ($entryCount < 2) {
            return 0;
        }

        return intdiv($entryCount * ($entryCount - 1), 2);
    }

    public static function hasCompleteGamesSchedule(Group $group): bool
    {
        $entryCount = $group->groupEntries()->count();

        if ($entryCount < 2) {
            return false;
        }

        return $group->games()->count() === self::expectedPairCount($entryCount);
    }

    public static function hasOpenGames(Group $group): bool
    {
        return $group->games()
            ->whereIn('status', [GameStatus::Pending, GameStatus::InProgress])
            ->exists();
    }
}
