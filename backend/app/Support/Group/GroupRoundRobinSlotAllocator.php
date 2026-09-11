<?php

namespace App\Support\Group;

use App\Models\Game;

final class GroupRoundRobinSlotAllocator
{
    /**
     * @param  iterable<int, Game>  $games
     * @return array<string, true>
     */
    public static function occupiedFromGames(iterable $games): array
    {
        $occupied = [];

        foreach ($games as $game) {
            if ($game->group_round === null || $game->group_match === null) {
                continue;
            }

            $occupied[self::key((int) $game->group_round, (int) $game->group_match)] = true;
        }

        return $occupied;
    }

    /**
     * @param  list<array{group_round: int, group_match: int}>  $slots
     */
    public static function officialMaxRound(array $slots): int
    {
        $maxRound = 0;

        foreach ($slots as $slot) {
            $maxRound = max($maxRound, (int) $slot['group_round']);
        }

        return $maxRound;
    }

    /**
     * @param  array<string, true>  $occupied
     * @return array{group_round: int, group_match: int}
     */
    public static function allocate(
        int $idealRound,
        int $idealMatch,
        array &$occupied,
        int $officialMaxRound,
    ): array {
        $idealKey = self::key($idealRound, $idealMatch);

        if (! isset($occupied[$idealKey])) {
            $occupied[$idealKey] = true;

            return [
                'group_round' => $idealRound,
                'group_match' => $idealMatch,
            ];
        }

        $round = max($officialMaxRound, 0) + 1;
        $match = 1;

        while (isset($occupied[self::key($round, $match)])) {
            $match++;
        }

        $occupied[self::key($round, $match)] = true;

        return [
            'group_round' => $round,
            'group_match' => $match,
        ];
    }

    public static function key(int $round, int $match): string
    {
        return sprintf('%d:%d', $round, $match);
    }

    /**
     * @param  iterable<int, Game>  $games
     * @return array<string, true>
     */
    public static function pairKeysFromGames(iterable $games): array
    {
        $pairs = [];

        foreach ($games as $game) {
            if ($game->entry1_id === null || $game->entry2_id === null) {
                continue;
            }

            $pairs[self::pairKey((int) $game->entry1_id, (int) $game->entry2_id)] = true;
        }

        return $pairs;
    }

    public static function pairKey(int $entry1Id, int $entry2Id): string
    {
        $pair = [$entry1Id, $entry2Id];
        sort($pair);

        return sprintf('%d-%d', $pair[0], $pair[1]);
    }
}
