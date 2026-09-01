<?php

namespace App\Support\Group;

use App\Models\Game;
use Illuminate\Support\Collection;

final class GroupRefereeAssigner
{
    /**
     * Assign a referee CompetitionEntry to each group fixture game.
     *
     * V1 pool: CompetitionEntries that appear in the fixture (entry1/entry2).
     * GroupEntry status is ignored so the same fixture always yields the same
     * referees, even after a later withdrawn/disqualified.
     *
     * Priority for each game:
     * 1. never a side of the match (hard)
     * 2. fewest assignments so far (equity)
     * 3. not the previous game's referee (consecutive, never over equity)
     * 4. most remaining games they can still referee (keeps later equity)
     * 5. lowest competition_entry_id
     *
     * @param  iterable<int, Game>  $orderedGames  Fixture already ordered by
     *                                             group_round NULLS LAST, group_match, id.
     * @return array<int, int|null> game_id => competition_entry_id|null
     */
    public function assign(iterable $orderedGames): array
    {
        $games = Collection::make($orderedGames)->values();
        $pool = $this->poolFromGames($games);
        $counts = array_fill_keys($pool, 0);
        $previousRefereeId = null;
        $assignment = [];

        foreach ($games as $index => $game) {
            $gameId = (int) $game->id;

            if ($game->is_bye) {
                $assignment[$gameId] = null;
                $previousRefereeId = null;

                continue;
            }

            $playing = $this->playingEntryIds($game);
            $candidates = array_values(array_filter(
                $pool,
                static fn (int $entryId): bool => ! in_array($entryId, $playing, true),
            ));

            if ($candidates === []) {
                $assignment[$gameId] = null;
                $previousRefereeId = null;

                continue;
            }

            $refereeId = $this->chooseReferee(
                $candidates,
                $counts,
                $previousRefereeId,
                $games,
                (int) $index,
            );
            $assignment[$gameId] = $refereeId;
            $counts[$refereeId] = ($counts[$refereeId] ?? 0) + 1;
            $previousRefereeId = $refereeId;
        }

        return $assignment;
    }

    /**
     * @param  Collection<int, Game>  $games
     * @return list<int>
     */
    private function poolFromGames(Collection $games): array
    {
        $entryIds = [];

        foreach ($games as $game) {
            foreach ($this->playingEntryIds($game) as $entryId) {
                $entryIds[$entryId] = $entryId;
            }
        }

        $pool = array_values($entryIds);
        sort($pool);

        return $pool;
    }

    /**
     * @return list<int>
     */
    private function playingEntryIds(Game $game): array
    {
        $ids = [];

        if ($game->entry1_id !== null) {
            $ids[] = (int) $game->entry1_id;
        }

        if ($game->entry2_id !== null) {
            $ids[] = (int) $game->entry2_id;
        }

        return $ids;
    }

    /**
     * @param  list<int>  $candidates
     * @param  array<int, int>  $counts
     * @param  Collection<int, Game>  $games
     */
    private function chooseReferee(
        array $candidates,
        array $counts,
        ?int $previousRefereeId,
        Collection $games,
        int $fromIndex,
    ): int {
        $minCount = min(array_map(
            static fn (int $entryId): int => $counts[$entryId] ?? 0,
            $candidates,
        ));

        $leastLoaded = array_values(array_filter(
            $candidates,
            static fn (int $entryId): bool => ($counts[$entryId] ?? 0) === $minCount,
        ));

        $tied = $leastLoaded;

        if ($previousRefereeId !== null) {
            $nonConsecutive = array_values(array_filter(
                $leastLoaded,
                static fn (int $entryId): bool => $entryId !== $previousRefereeId,
            ));

            if ($nonConsecutive !== []) {
                $tied = $nonConsecutive;
            }
        }

        $availabilities = [];

        foreach ($tied as $entryId) {
            $availabilities[$entryId] = $this->remainingAvailability($entryId, $games, $fromIndex);
        }

        $maxAvailability = max($availabilities);
        $mostAvailable = array_values(array_filter(
            $tied,
            static fn (int $entryId): bool => $availabilities[$entryId] === $maxAvailability,
        ));

        return min($mostAvailable);
    }

    /**
     * @param  Collection<int, Game>  $games
     */
    private function remainingAvailability(int $entryId, Collection $games, int $fromIndex): int
    {
        $available = 0;

        for ($index = $fromIndex; $index < $games->count(); $index++) {
            $playing = $this->playingEntryIds($games[$index]);

            if (! in_array($entryId, $playing, true)) {
                $available++;
            }
        }

        return $available;
    }
}
