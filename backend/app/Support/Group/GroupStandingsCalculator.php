<?php

namespace App\Support\Group;

use App\Data\Competition\CompetitionStandingData;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\Group;
use App\Models\GroupManualTiebreak;

final class GroupStandingsCalculator
{
    public function calculate(Group $group): GroupStandingsResult
    {
        $automatic = $this->calculateAutomatic($group);

        return $this->applyPersistedManualTiebreaks($group, $automatic);
    }

    public function calculateAutomaticOnly(Group $group): GroupStandingsResult
    {
        $automatic = $this->calculateAutomatic($group);

        return $this->buildStandingsResult(
            automatic: $automatic,
            appliedManualTiebreaks: [],
            staleManualTiebreaks: [],
        );
    }

    /**
     * @return array{
     *     stats_by_player: array<int, array{won: int, lost: int}>,
     *     player_name_by_id: array<int, string>,
     *     ordered_player_ids: array<int, int>,
     *     manual_tie_groups: array<int, array<int, int>>
     * }
     */
    private function calculateAutomatic(Group $group): array
    {
        $groupPlayers = $group->groupPlayers()
            ->with('player:id,first_name,last_name')
            ->get();

        $playerIds = $groupPlayers
            ->pluck('player_id')
            ->map(fn (int $playerId): int => (int) $playerId)
            ->all();

        $playerNameById = $groupPlayers
            ->mapWithKeys(function ($groupPlayer): array {
                $playerId = (int) $groupPlayer->player_id;

                return [
                    $playerId => trim(sprintf(
                        '%s %s',
                        (string) $groupPlayer->player?->first_name,
                        (string) $groupPlayer->player?->last_name
                    )),
                ];
            })
            ->all();

        $statsByPlayer = [];

        foreach ($playerIds as $playerId) {
            $statsByPlayer[$playerId] = [
                'won' => 0,
                'lost' => 0,
            ];
        }

        $finishedGames = $group->games()
            ->select(['id', 'player1_id', 'player2_id', 'winner_id'])
            ->with('sets:id,game_id,player1_score,player2_score')
            ->where('status', GameStatus::Finished)
            ->whereNotNull('winner_id')
            ->get();

        foreach ($finishedGames as $game) {
            $winnerId = (int) $game->winner_id;
            $loserId = $winnerId === (int) $game->player1_id
                ? (int) $game->player2_id
                : (int) $game->player1_id;

            if (isset($statsByPlayer[$winnerId])) {
                $statsByPlayer[$winnerId]['won']++;
            }

            if (isset($statsByPlayer[$loserId])) {
                $statsByPlayer[$loserId]['lost']++;
            }
        }

        $orderedPlayerIds = [];
        $manualTieGroups = [];

        $playersByWins = collect($playerIds)
            ->groupBy(fn (int $playerId): int => (int) ($statsByPlayer[$playerId]['won'] ?? 0))
            ->sortKeysDesc();

        foreach ($playersByWins as $playersWithSameWins) {
            $tiedPlayerIds = array_values(array_map('intval', $playersWithSameWins->all()));

            if (count($tiedPlayerIds) === 1) {
                $orderedPlayerIds[] = $tiedPlayerIds[0];

                continue;
            }

            $resolvedTie = $this->resolveTieGroup(
                tiedPlayerIds: $tiedPlayerIds,
                finishedGames: $finishedGames->all(),
                playerNameById: $playerNameById,
            );

            $orderedPlayerIds = [...$orderedPlayerIds, ...$resolvedTie['ordered_player_ids']];
            $manualTieGroups = [...$manualTieGroups, ...$resolvedTie['manual_groups']];
        }

        return [
            'stats_by_player' => $statsByPlayer,
            'player_name_by_id' => $playerNameById,
            'ordered_player_ids' => $orderedPlayerIds,
            'manual_tie_groups' => $manualTieGroups,
        ];
    }

    /**
     * @param  array{
     *     stats_by_player: array<int, array{won: int, lost: int}>,
     *     player_name_by_id: array<int, string>,
     *     ordered_player_ids: array<int, int>,
     *     manual_tie_groups: array<int, array<int, int>>
     * }  $automatic
     */
    private function applyPersistedManualTiebreaks(Group $group, array $automatic): GroupStandingsResult
    {
        $orderedPlayerIds = $automatic['ordered_player_ids'];
        $pendingManualGroups = $automatic['manual_tie_groups'];
        $playerNameById = $automatic['player_name_by_id'];

        $persistedTiebreaks = $group->manualTiebreaks()
            ->with(['players.player:id,first_name,last_name'])
            ->orderBy('id')
            ->get();

        $appliedManualTiebreaks = [];
        $staleManualTiebreaks = [];
        $manualPositionByPlayerId = [];
        $appliedPlayerFlags = [];

        $remainingPendingGroups = [];

        foreach ($pendingManualGroups as $pendingGroup) {
            $matchingTiebreak = $this->findMatchingTiebreak($persistedTiebreaks, $pendingGroup);

            if ($matchingTiebreak === null) {
                $remainingPendingGroups[] = $pendingGroup;

                continue;
            }

            $manualOrder = $matchingTiebreak->orderedPlayerIds();
            $orderedPlayerIds = $this->replaceContiguousBlock(
                orderedIds: $orderedPlayerIds,
                blockPlayerIds: $pendingGroup,
                manualOrder: $manualOrder,
            );

            foreach ($manualOrder as $index => $playerId) {
                $manualPositionByPlayerId[$playerId] = $index + 1;
                $appliedPlayerFlags[$playerId] = true;
            }

            $appliedManualTiebreaks[] = $this->formatTiebreakRecord($matchingTiebreak, $playerNameById);
            $persistedTiebreaks = $persistedTiebreaks->reject(
                fn (GroupManualTiebreak $tiebreak): bool => $tiebreak->id === $matchingTiebreak->id
            );
        }

        foreach ($persistedTiebreaks as $staleTiebreak) {
            $staleManualTiebreaks[] = $this->formatTiebreakRecord($staleTiebreak, $playerNameById);
        }

        return $this->buildStandingsResult(
            automatic: [
                ...$automatic,
                'ordered_player_ids' => $orderedPlayerIds,
                'manual_tie_groups' => $remainingPendingGroups,
            ],
            appliedManualTiebreaks: $appliedManualTiebreaks,
            staleManualTiebreaks: $staleManualTiebreaks,
            manualPositionByPlayerId: $manualPositionByPlayerId,
            appliedPlayerFlags: $appliedPlayerFlags,
        );
    }

    /**
     * @param  array{
     *     stats_by_player: array<int, array{won: int, lost: int}>,
     *     player_name_by_id: array<int, string>,
     *     ordered_player_ids: array<int, int>,
     *     manual_tie_groups: array<int, array<int, int>>
     * }  $automatic
     * @param  array<int, array{id: int, player_ids: array<int, int>, player_names: array<int, string>, reason: string, notes: ?string, applied_at: string}>  $appliedManualTiebreaks
     * @param  array<int, array{id: int, player_ids: array<int, int>, player_names: array<int, string>, reason: string, notes: ?string, applied_at: string}>  $staleManualTiebreaks
     * @param  array<int, int>  $manualPositionByPlayerId
     * @param  array<int, bool>  $appliedPlayerFlags
     */
    private function buildStandingsResult(
        array $automatic,
        array $appliedManualTiebreaks,
        array $staleManualTiebreaks,
        array $manualPositionByPlayerId = [],
        array $appliedPlayerFlags = [],
    ): GroupStandingsResult {
        $statsByPlayer = $automatic['stats_by_player'];
        $playerNameById = $automatic['player_name_by_id'];
        $orderedPlayerIds = $automatic['ordered_player_ids'];
        $manualTieGroups = $automatic['manual_tie_groups'];

        $manualPlayerFlags = [];

        foreach ($manualTieGroups as $manualTieGroup) {
            foreach ($manualTieGroup as $playerId) {
                $manualPlayerFlags[$playerId] = true;
            }
        }

        $standings = collect($orderedPlayerIds)
            ->map(function (int $playerId) use (
                $playerNameById,
                $statsByPlayer,
                $manualPlayerFlags,
                $appliedPlayerFlags,
                $manualPositionByPlayerId,
            ): CompetitionStandingData {
                $stats = $statsByPlayer[$playerId] ?? ['won' => 0, 'lost' => 0];

                return new CompetitionStandingData(
                    playerId: $playerId,
                    playerName: $playerNameById[$playerId] ?? '',
                    won: (int) $stats['won'],
                    lost: (int) $stats['lost'],
                    requiresManualTiebreak: (bool) ($manualPlayerFlags[$playerId] ?? false),
                    manualTiebreakApplied: (bool) ($appliedPlayerFlags[$playerId] ?? false),
                    manualPosition: $appliedPlayerFlags[$playerId] ?? false
                        ? ($manualPositionByPlayerId[$playerId] ?? null)
                        : null,
                );
            })
            ->values();

        $manualTiebreakGroups = array_map(
            function (array $group) use ($playerNameById): array {
                return [
                    'player_ids' => $group,
                    'player_names' => array_map(
                        fn (int $playerId): string => (string) ($playerNameById[$playerId] ?? ''),
                        $group
                    ),
                ];
            },
            $manualTieGroups
        );

        return new GroupStandingsResult(
            standings: $standings,
            manualTiebreakGroups: $manualTiebreakGroups,
            appliedManualTiebreaks: $appliedManualTiebreaks,
            staleManualTiebreaks: $staleManualTiebreaks,
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int, GroupManualTiebreak>  $persistedTiebreaks
     * @param  array<int, int>  $pendingGroup
     */
    private function findMatchingTiebreak($persistedTiebreaks, array $pendingGroup): ?GroupManualTiebreak
    {
        foreach ($persistedTiebreaks as $tiebreak) {
            if ($this->playerSetsMatch($tiebreak->orderedPlayerIds(), $pendingGroup)) {
                return $tiebreak;
            }
        }

        return null;
    }

    /**
     * @param  array<int, int>  $left
     * @param  array<int, int>  $right
     */
    private function playerSetsMatch(array $left, array $right): bool
    {
        $leftSorted = array_map('intval', $left);
        $rightSorted = array_map('intval', $right);
        sort($leftSorted);
        sort($rightSorted);

        return $leftSorted === $rightSorted;
    }

    /**
     * @param  array<int, int>  $orderedIds
     * @param  array<int, int>  $blockPlayerIds
     * @param  array<int, int>  $manualOrder
     * @return array<int, int>
     */
    private function replaceContiguousBlock(array $orderedIds, array $blockPlayerIds, array $manualOrder): array
    {
        $blockIndex = $this->findContiguousBlockIndex($orderedIds, $blockPlayerIds);

        if ($blockIndex === null) {
            return $orderedIds;
        }

        $blockLength = count($blockPlayerIds);

        return [
            ...array_slice($orderedIds, 0, $blockIndex),
            ...$manualOrder,
            ...array_slice($orderedIds, $blockIndex + $blockLength),
        ];
    }

    /**
     * @param  array<int, int>  $orderedIds
     * @param  array<int, int>  $playerIds
     */
    private function findContiguousBlockIndex(array $orderedIds, array $playerIds): ?int
    {
        $blockLength = count($playerIds);

        if ($blockLength === 0 || count($orderedIds) < $blockLength) {
            return null;
        }

        for ($index = 0; $index <= count($orderedIds) - $blockLength; $index++) {
            $window = array_slice($orderedIds, $index, $blockLength);

            if ($this->playerSetsMatch($window, $playerIds)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $playerNameById
     * @return array{id: int, player_ids: array<int, int>, player_names: array<int, string>, reason: string, notes: ?string, applied_at: string}
     */
    private function formatTiebreakRecord(GroupManualTiebreak $tiebreak, array $playerNameById): array
    {
        $playerIds = $tiebreak->orderedPlayerIds();

        return [
            'id' => (int) $tiebreak->id,
            'player_ids' => $playerIds,
            'player_names' => array_map(
                fn (int $playerId): string => (string) ($playerNameById[$playerId] ?? ''),
                $playerIds
            ),
            'reason' => $tiebreak->reason->value,
            'notes' => $tiebreak->notes,
            'applied_at' => $tiebreak->applied_at?->toIso8601String() ?? '',
        ];
    }

    /**
     * @param  array<int, int>  $tiedPlayerIds
     * @param  array<int, Game>  $finishedGames
     * @param  array<int, string>  $playerNameById
     * @return array{
     *     ordered_player_ids: array<int, int>,
     *     manual_groups: array<int, array<int, int>>
     * }
     */
    private function resolveTieGroup(
        array $tiedPlayerIds,
        array $finishedGames,
        array $playerNameById,
    ): array {
        $tiedPlayerLookup = array_fill_keys($tiedPlayerIds, true);
        $miniStats = [];

        foreach ($tiedPlayerIds as $playerId) {
            $miniStats[$playerId] = [
                'mini_won' => 0,
                'set_diff' => 0,
                'point_diff' => 0,
            ];
        }

        foreach ($finishedGames as $game) {
            $player1Id = (int) $game->player1_id;
            $player2Id = (int) $game->player2_id;

            if (! isset($tiedPlayerLookup[$player1Id], $tiedPlayerLookup[$player2Id])) {
                continue;
            }

            $winnerId = (int) $game->winner_id;

            if (isset($miniStats[$winnerId])) {
                $miniStats[$winnerId]['mini_won']++;
            }

            $setsWon = $game->setsWonCount($game->sets);
            $player1SetDiff = $setsWon['player1'] - $setsWon['player2'];
            $player2SetDiff = -$player1SetDiff;

            $player1Points = 0;
            $player2Points = 0;

            foreach ($game->sets as $set) {
                $player1Points += (int) $set->player1_score;
                $player2Points += (int) $set->player2_score;
            }

            $player1PointDiff = $player1Points - $player2Points;
            $player2PointDiff = -$player1PointDiff;

            $miniStats[$player1Id]['set_diff'] += $player1SetDiff;
            $miniStats[$player2Id]['set_diff'] += $player2SetDiff;
            $miniStats[$player1Id]['point_diff'] += $player1PointDiff;
            $miniStats[$player2Id]['point_diff'] += $player2PointDiff;
        }

        return $this->rankByMiniCriteria(
            playerIds: $tiedPlayerIds,
            miniStats: $miniStats,
            playerNameById: $playerNameById,
            criteria: ['mini_won', 'set_diff', 'point_diff'],
            currentCriterion: 0,
        );
    }

    /**
     * @param  array<int, int>  $playerIds
     * @param  array<int, array{mini_won: int, set_diff: int, point_diff: int}>  $miniStats
     * @param  array<int, string>  $playerNameById
     * @param  array<int, string>  $criteria
     * @return array{
     *     ordered_player_ids: array<int, int>,
     *     manual_groups: array<int, array<int, int>>
     * }
     */
    private function rankByMiniCriteria(
        array $playerIds,
        array $miniStats,
        array $playerNameById,
        array $criteria,
        int $currentCriterion,
    ): array {
        if (count($playerIds) <= 1) {
            return [
                'ordered_player_ids' => $playerIds,
                'manual_groups' => [],
            ];
        }

        $criterion = $criteria[$currentCriterion] ?? null;

        if ($criterion === null) {
            $orderedByName = $this->sortPlayersByName($playerIds, $playerNameById);

            return [
                'ordered_player_ids' => $orderedByName,
                'manual_groups' => [$orderedByName],
            ];
        }

        $groupsByCriterionValue = [];

        foreach ($playerIds as $playerId) {
            $value = (int) ($miniStats[$playerId][$criterion] ?? 0);
            $groupsByCriterionValue[$value] ??= [];
            $groupsByCriterionValue[$value][] = $playerId;
        }

        krsort($groupsByCriterionValue, SORT_NUMERIC);

        $orderedPlayerIds = [];
        $manualGroups = [];

        foreach ($groupsByCriterionValue as $playersWithSameValue) {
            if (count($playersWithSameValue) === 1) {
                $orderedPlayerIds[] = $playersWithSameValue[0];

                continue;
            }

            $resolvedSubGroup = $this->rankByMiniCriteria(
                playerIds: $playersWithSameValue,
                miniStats: $miniStats,
                playerNameById: $playerNameById,
                criteria: $criteria,
                currentCriterion: $currentCriterion + 1,
            );

            $orderedPlayerIds = [...$orderedPlayerIds, ...$resolvedSubGroup['ordered_player_ids']];
            $manualGroups = [...$manualGroups, ...$resolvedSubGroup['manual_groups']];
        }

        return [
            'ordered_player_ids' => $orderedPlayerIds,
            'manual_groups' => $manualGroups,
        ];
    }

    /**
     * @param  array<int, int>  $playerIds
     * @param  array<int, string>  $playerNameById
     * @return array<int, int>
     */
    private function sortPlayersByName(array $playerIds, array $playerNameById): array
    {
        usort($playerIds, function (int $leftPlayerId, int $rightPlayerId) use ($playerNameById): int {
            $leftName = strtolower((string) ($playerNameById[$leftPlayerId] ?? ''));
            $rightName = strtolower((string) ($playerNameById[$rightPlayerId] ?? ''));

            return [$leftName, $leftPlayerId] <=> [$rightName, $rightPlayerId];
        });

        return $playerIds;
    }
}
