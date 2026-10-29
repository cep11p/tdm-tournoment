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

    /**
     * El fixture está completo cuando cada par de participantes activos tiene
     * su partido. Los partidos históricos contra participantes dados de baja
     * no cuentan ni faltan. Con menos de 2 activos no se requieren partidos.
     */
    public static function hasCompleteGamesSchedule(Group $group): bool
    {
        if ($group->groupEntries()->count() < 2) {
            return false;
        }

        $activeEntryIds = GroupActiveEntries::ids($group);

        if (count($activeEntryIds) < 2) {
            return true;
        }

        $existingPairs = GroupRoundRobinSlotAllocator::pairKeysFromGames(
            $group->games()->get(['id', 'entry1_id', 'entry2_id']),
        );
        $count = count($activeEntryIds);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $pairKey = GroupRoundRobinSlotAllocator::pairKey($activeEntryIds[$i], $activeEntryIds[$j]);

                if (! isset($existingPairs[$pairKey])) {
                    return false;
                }
            }
        }

        return true;
    }

    public static function hasOpenGames(Group $group): bool
    {
        return $group->games()
            ->whereIn('status', [GameStatus::Pending, GameStatus::InProgress])
            ->exists();
    }
}
