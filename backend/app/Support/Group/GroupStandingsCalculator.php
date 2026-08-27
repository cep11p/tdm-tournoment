<?php

namespace App\Support\Group;

use App\Data\Competition\CompetitionStandingData;
use App\Enums\GameStatus;
use App\Enums\GroupPlayerStatus;
use App\Models\Game;
use App\Models\Group;
use App\Models\GroupManualTiebreak;
use App\Support\Competition\BuildSinglesEntryIndexForGroup;
use App\Support\Competition\SinglesEntryIndex;
use Illuminate\Support\Collection;

final class GroupStandingsCalculator
{
    public function __construct(
        private readonly BuildSinglesEntryIndexForGroup $buildSinglesEntryIndexForGroup,
    ) {}

    public function calculate(Group $group): GroupStandingsResult
    {
        $gamesProgress = $this->resolveGroupGamesProgress($group);
        $automatic = $this->calculateAutomatic($group);

        if ($gamesProgress['is_provisional']) {
            $automatic['pending_manual_tie_entry_groups'] = [];
        }

        return $this->applyPersistedManualTiebreaks($group, $automatic, $gamesProgress);
    }

    public function calculateAutomaticOnly(Group $group): GroupStandingsResult
    {
        $gamesProgress = $this->resolveGroupGamesProgress($group);
        $automatic = $this->calculateAutomatic($group);

        if ($gamesProgress['is_provisional']) {
            $automatic['pending_manual_tie_entry_groups'] = [];
        }

        return $this->buildStandingsResult(
            automatic: $automatic,
            appliedManualTiebreaks: [],
            staleManualTiebreaks: [],
            gamesProgress: $gamesProgress,
        );
    }

    public function isGroupComplete(Group $group): bool
    {
        return $this->resolveGroupGamesProgress($group)['is_complete'];
    }

    /**
     * @return array{
     *     is_complete: bool,
     *     is_provisional: bool,
     *     completed_games_count: int,
     *     total_games_count: int
     * }
     */
    private function resolveGroupGamesProgress(Group $group): array
    {
        $totalGamesCount = $group->games()->count();

        if ($totalGamesCount === 0) {
            return [
                'is_complete' => false,
                'is_provisional' => true,
                'completed_games_count' => 0,
                'total_games_count' => 0,
            ];
        }

        $completedGamesCount = $group->games()
            ->where('status', GameStatus::Finished)
            ->count();

        $hasUnfinishedGames = $group->games()
            ->where('status', '!=', GameStatus::Finished)
            ->exists();

        return [
            'is_complete' => ! $hasUnfinishedGames,
            'is_provisional' => $hasUnfinishedGames,
            'completed_games_count' => $completedGamesCount,
            'total_games_count' => $totalGamesCount,
        ];
    }

    /**
     * @return array{
     *     stats_by_entry: array<int, array{won: int, lost: int}>,
     *     ordered_entry_ids: array<int, int>,
     *     pending_manual_tie_entry_groups: array<int, array<int, int>>,
     *     index: SinglesEntryIndex
     * }
     */
    private function calculateAutomatic(Group $group): array
    {
        $index = ($this->buildSinglesEntryIndexForGroup)($group);
        $entryIds = $index->entryIds();

        $activeEntryIds = [];
        $inactiveEntryIds = [];

        foreach ($entryIds as $entryId) {
            if ($index->statusForEntry($entryId) === GroupPlayerStatus::Active) {
                $activeEntryIds[] = $entryId;
            } else {
                $inactiveEntryIds[] = $entryId;
            }
        }

        $statsByEntry = [];

        foreach ($entryIds as $entryId) {
            $statsByEntry[$entryId] = [
                'won' => 0,
                'lost' => 0,
            ];
        }

        $finishedGames = $group->games()
            ->select(['id', 'entry1_id', 'entry2_id', 'winner_entry_id'])
            ->with('sets:id,game_id,player1_score,player2_score')
            ->where('status', GameStatus::Finished)
            ->whereNotNull('winner_entry_id')
            ->get();

        foreach ($finishedGames as $game) {
            $winnerEntryId = $game->winner_entry_id !== null ? (int) $game->winner_entry_id : null;
            $player1EntryId = (int) $game->entry1_id;
            $player2EntryId = $game->entry2_id !== null ? (int) $game->entry2_id : null;

            if ($winnerEntryId === null) {
                continue;
            }

            $loserEntryId = $winnerEntryId === $player1EntryId
                ? $player2EntryId
                : $player1EntryId;

            if (isset($statsByEntry[$winnerEntryId])) {
                $statsByEntry[$winnerEntryId]['won']++;
            }

            if ($loserEntryId !== null && isset($statsByEntry[$loserEntryId])) {
                $statsByEntry[$loserEntryId]['lost']++;
            }
        }

        $orderedEntryIds = [];
        $pendingManualTieEntryGroups = [];

        $entriesByWins = collect($activeEntryIds)
            ->groupBy(fn (int $entryId): int => (int) ($statsByEntry[$entryId]['won'] ?? 0))
            ->sortKeysDesc();

        foreach ($entriesByWins as $entriesWithSameWins) {
            $tiedEntryIds = array_values(array_map('intval', $entriesWithSameWins->all()));

            if (count($tiedEntryIds) === 1) {
                $orderedEntryIds[] = $tiedEntryIds[0];

                continue;
            }

            $resolvedTie = $this->resolveTieGroup(
                tiedEntryIds: $tiedEntryIds,
                finishedGames: $finishedGames->all(),
                index: $index,
            );

            $orderedEntryIds = [...$orderedEntryIds, ...$resolvedTie['ordered_entry_ids']];
            $pendingManualTieEntryGroups = [...$pendingManualTieEntryGroups, ...$resolvedTie['manual_groups']];
        }

        if ($inactiveEntryIds !== []) {
            $orderedEntryIds = [
                ...$orderedEntryIds,
                ...$this->sortEntriesByName($inactiveEntryIds, $index),
            ];
        }

        return [
            'stats_by_entry' => $statsByEntry,
            'ordered_entry_ids' => $orderedEntryIds,
            'pending_manual_tie_entry_groups' => $pendingManualTieEntryGroups,
            'index' => $index,
        ];
    }

    /**
     * @param  array{
     *     stats_by_entry: array<int, array{won: int, lost: int}>,
     *     ordered_entry_ids: array<int, int>,
     *     pending_manual_tie_entry_groups: array<int, array<int, int>>,
     *     index: SinglesEntryIndex
     * }  $automatic
     */
    private function applyPersistedManualTiebreaks(Group $group, array $automatic, array $gamesProgress): GroupStandingsResult
    {
        $orderedEntryIds = $automatic['ordered_entry_ids'];
        $pendingManualTieEntryGroups = $automatic['pending_manual_tie_entry_groups'];
        $index = $automatic['index'];

        $persistedTiebreaks = $group->manualTiebreaks()
            ->with(['entries'])
            ->orderBy('id')
            ->get();

        $appliedManualTiebreaks = [];
        $staleManualTiebreaks = [];
        $manualPositionByEntryId = [];
        $appliedEntryFlags = [];

        $remainingPendingGroups = [];

        foreach ($pendingManualTieEntryGroups as $pendingGroup) {
            $matchingTiebreak = $this->findMatchingTiebreak($persistedTiebreaks, $pendingGroup);

            if ($matchingTiebreak === null) {
                $remainingPendingGroups[] = $pendingGroup;

                continue;
            }

            $manualOrder = $matchingTiebreak->orderedCompetitionEntryIds();
            $orderedEntryIds = $this->replaceContiguousBlock(
                orderedIds: $orderedEntryIds,
                blockEntryIds: $pendingGroup,
                manualOrder: $manualOrder,
            );

            foreach ($manualOrder as $positionIndex => $entryId) {
                $manualPositionByEntryId[$entryId] = $positionIndex + 1;
                $appliedEntryFlags[$entryId] = true;
            }

            $appliedManualTiebreaks[] = $this->formatPublicTiebreakRecord($matchingTiebreak, $index);
            $persistedTiebreaks = $persistedTiebreaks->reject(
                fn (GroupManualTiebreak $tiebreak): bool => $tiebreak->id === $matchingTiebreak->id
            );
        }

        if (! $gamesProgress['is_provisional']) {
            foreach ($persistedTiebreaks as $staleTiebreak) {
                $staleManualTiebreaks[] = $this->formatPublicTiebreakRecord($staleTiebreak, $index);
            }
        }

        return $this->buildStandingsResult(
            automatic: [
                ...$automatic,
                'ordered_entry_ids' => $orderedEntryIds,
                'pending_manual_tie_entry_groups' => $remainingPendingGroups,
            ],
            appliedManualTiebreaks: $appliedManualTiebreaks,
            staleManualTiebreaks: $staleManualTiebreaks,
            manualPositionByEntryId: $manualPositionByEntryId,
            appliedEntryFlags: $appliedEntryFlags,
            gamesProgress: $gamesProgress,
        );
    }

    /**
     * @param  array{
     *     stats_by_entry: array<int, array{won: int, lost: int}>,
     *     ordered_entry_ids: array<int, int>,
     *     pending_manual_tie_entry_groups: array<int, array<int, int>>,
     *     index: SinglesEntryIndex
     * }  $automatic
     * @param  array<int, array{id: int, player_ids: array<int, int>, player_names: array<int, string>, reason: string, notes: ?string, applied_at: string}>  $appliedManualTiebreaks
     * @param  array<int, array{id: int, player_ids: array<int, int>, player_names: array<int, string>, reason: string, notes: ?string, applied_at: string}>  $staleManualTiebreaks
     * @param  array<int, int>  $manualPositionByEntryId
     * @param  array<int, bool>  $appliedEntryFlags
     * @param  array{
     *     is_complete: bool,
     *     is_provisional: bool,
     *     completed_games_count: int,
     *     total_games_count: int
     * }  $gamesProgress
     */
    private function buildStandingsResult(
        array $automatic,
        array $appliedManualTiebreaks,
        array $staleManualTiebreaks,
        array $manualPositionByEntryId = [],
        array $appliedEntryFlags = [],
        array $gamesProgress = [
            'is_complete' => true,
            'is_provisional' => false,
            'completed_games_count' => 0,
            'total_games_count' => 0,
        ],
    ): GroupStandingsResult {
        $statsByEntry = $automatic['stats_by_entry'];
        $orderedEntryIds = $automatic['ordered_entry_ids'];
        $pendingManualTieEntryGroups = $automatic['pending_manual_tie_entry_groups'];
        $index = $automatic['index'];

        $manualEntryFlags = [];

        foreach ($pendingManualTieEntryGroups as $manualTieGroup) {
            foreach ($manualTieGroup as $entryId) {
                $manualEntryFlags[$entryId] = true;
            }
        }

        $standings = collect($orderedEntryIds)
            ->map(function (int $entryId) use (
                $index,
                $statsByEntry,
                $manualEntryFlags,
                $appliedEntryFlags,
                $manualPositionByEntryId,
            ): CompetitionStandingData {
                $stats = $statsByEntry[$entryId] ?? ['won' => 0, 'lost' => 0];
                $status = $index->statusForEntry($entryId);
                $isActive = $status === GroupPlayerStatus::Active;

                return new CompetitionStandingData(
                    playerId: (int) ($index->playerIdForEntry($entryId) ?? 0),
                    playerName: $index->playerNameForEntry($entryId),
                    won: (int) $stats['won'],
                    lost: (int) $stats['lost'],
                    competitionEntryId: $entryId,
                    requiresManualTiebreak: $isActive && (bool) ($manualEntryFlags[$entryId] ?? false),
                    manualTiebreakApplied: (bool) ($appliedEntryFlags[$entryId] ?? false),
                    manualPosition: $appliedEntryFlags[$entryId] ?? false
                        ? ($manualPositionByEntryId[$entryId] ?? null)
                        : null,
                    eligibleForQualification: $isActive,
                    groupPlayerStatus: $status->value,
                );
            })
            ->values();

        $manualTiebreakGroups = array_map(
            function (array $entryIds) use ($index): array {
                return [
                    'player_ids' => $index->playerIdsForEntries($entryIds),
                    'player_names' => $index->playerNamesForEntries($entryIds),
                ];
            },
            $pendingManualTieEntryGroups
        );

        return new GroupStandingsResult(
            standings: $standings,
            pendingManualTieEntryGroups: $pendingManualTieEntryGroups,
            manualTiebreakGroups: $manualTiebreakGroups,
            appliedManualTiebreaks: $appliedManualTiebreaks,
            staleManualTiebreaks: $staleManualTiebreaks,
            isProvisional: (bool) $gamesProgress['is_provisional'],
            completedGamesCount: (int) $gamesProgress['completed_games_count'],
            totalGamesCount: (int) $gamesProgress['total_games_count'],
        );
    }

    /**
     * @param  Collection<int, GroupManualTiebreak>  $persistedTiebreaks
     * @param  array<int, int>  $pendingGroup
     */
    private function findMatchingTiebreak($persistedTiebreaks, array $pendingGroup): ?GroupManualTiebreak
    {
        foreach ($persistedTiebreaks as $tiebreak) {
            if ($this->entrySetsMatch($tiebreak->orderedCompetitionEntryIds(), $pendingGroup)) {
                return $tiebreak;
            }
        }

        return null;
    }

    /**
     * @param  array<int, int>  $left
     * @param  array<int, int>  $right
     */
    private function entrySetsMatch(array $left, array $right): bool
    {
        $leftSorted = array_map('intval', $left);
        $rightSorted = array_map('intval', $right);
        sort($leftSorted);
        sort($rightSorted);

        return $leftSorted === $rightSorted;
    }

    /**
     * @param  array<int, int>  $orderedIds
     * @param  array<int, int>  $blockEntryIds
     * @param  array<int, int>  $manualOrder
     * @return array<int, int>
     */
    private function replaceContiguousBlock(array $orderedIds, array $blockEntryIds, array $manualOrder): array
    {
        $blockIndex = $this->findContiguousBlockIndex($orderedIds, $blockEntryIds);

        if ($blockIndex === null) {
            return $orderedIds;
        }

        $blockLength = count($blockEntryIds);

        return [
            ...array_slice($orderedIds, 0, $blockIndex),
            ...$manualOrder,
            ...array_slice($orderedIds, $blockIndex + $blockLength),
        ];
    }

    /**
     * @param  array<int, int>  $orderedIds
     * @param  array<int, int>  $entryIds
     */
    private function findContiguousBlockIndex(array $orderedIds, array $entryIds): ?int
    {
        $blockLength = count($entryIds);

        if ($blockLength === 0 || count($orderedIds) < $blockLength) {
            return null;
        }

        for ($index = 0; $index <= count($orderedIds) - $blockLength; $index++) {
            $window = array_slice($orderedIds, $index, $blockLength);

            if ($this->entrySetsMatch($window, $entryIds)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @return array{id: int, player_ids: array<int, int>, player_names: array<int, string>, reason: string, notes: ?string, applied_at: string}
     */
    private function formatPublicTiebreakRecord(GroupManualTiebreak $tiebreak, SinglesEntryIndex $index): array
    {
        $entryIds = $tiebreak->orderedCompetitionEntryIds();

        return [
            'id' => (int) $tiebreak->id,
            'player_ids' => $index->playerIdsForEntries($entryIds),
            'player_names' => $index->playerNamesForEntries($entryIds),
            'reason' => $tiebreak->reason->value,
            'notes' => $tiebreak->notes,
            'applied_at' => $tiebreak->applied_at?->toIso8601String() ?? '',
        ];
    }

    /**
     * @param  array<int, int>  $tiedEntryIds
     * @param  array<int, Game>  $finishedGames
     * @return array{
     *     ordered_entry_ids: array<int, int>,
     *     manual_groups: array<int, array<int, int>>
     * }
     */
    private function resolveTieGroup(
        array $tiedEntryIds,
        array $finishedGames,
        SinglesEntryIndex $index,
    ): array {
        $tiedEntryLookup = array_fill_keys($tiedEntryIds, true);
        $miniStats = [];

        foreach ($tiedEntryIds as $entryId) {
            $miniStats[$entryId] = [
                'mini_won' => 0,
                'set_diff' => 0,
                'point_diff' => 0,
            ];
        }

        foreach ($finishedGames as $game) {
            $player1EntryId = (int) $game->entry1_id;
            $player2EntryId = $game->entry2_id !== null ? (int) $game->entry2_id : null;

            if (
                $player2EntryId === null
                || ! isset($tiedEntryLookup[$player1EntryId], $tiedEntryLookup[$player2EntryId])
            ) {
                continue;
            }

            $winnerEntryId = $game->winner_entry_id !== null ? (int) $game->winner_entry_id : null;

            if ($winnerEntryId !== null && isset($miniStats[$winnerEntryId])) {
                $miniStats[$winnerEntryId]['mini_won']++;
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

            $miniStats[$player1EntryId]['set_diff'] += $player1SetDiff;
            $miniStats[$player2EntryId]['set_diff'] += $player2SetDiff;
            $miniStats[$player1EntryId]['point_diff'] += $player1PointDiff;
            $miniStats[$player2EntryId]['point_diff'] += $player2PointDiff;
        }

        return $this->rankByMiniCriteria(
            entryIds: $tiedEntryIds,
            miniStats: $miniStats,
            index: $index,
            criteria: ['mini_won', 'set_diff', 'point_diff'],
            currentCriterion: 0,
        );
    }

    /**
     * @param  array<int, int>  $entryIds
     * @param  array<int, array{mini_won: int, set_diff: int, point_diff: int}>  $miniStats
     * @param  array<int, string>  $criteria
     * @return array{
     *     ordered_entry_ids: array<int, int>,
     *     manual_groups: array<int, array<int, int>>
     * }
     */
    private function rankByMiniCriteria(
        array $entryIds,
        array $miniStats,
        SinglesEntryIndex $index,
        array $criteria,
        int $currentCriterion,
    ): array {
        if (count($entryIds) <= 1) {
            return [
                'ordered_entry_ids' => $entryIds,
                'manual_groups' => [],
            ];
        }

        $criterion = $criteria[$currentCriterion] ?? null;

        if ($criterion === null) {
            $orderedByName = $this->sortEntriesByName($entryIds, $index);

            return [
                'ordered_entry_ids' => $orderedByName,
                'manual_groups' => [$orderedByName],
            ];
        }

        $groupsByCriterionValue = [];

        foreach ($entryIds as $entryId) {
            $value = (int) ($miniStats[$entryId][$criterion] ?? 0);
            $groupsByCriterionValue[$value] ??= [];
            $groupsByCriterionValue[$value][] = $entryId;
        }

        krsort($groupsByCriterionValue, SORT_NUMERIC);

        $orderedEntryIds = [];
        $manualGroups = [];

        foreach ($groupsByCriterionValue as $entriesWithSameValue) {
            if (count($entriesWithSameValue) === 1) {
                $orderedEntryIds[] = $entriesWithSameValue[0];

                continue;
            }

            $resolvedSubGroup = $this->rankByMiniCriteria(
                entryIds: $entriesWithSameValue,
                miniStats: $miniStats,
                index: $index,
                criteria: $criteria,
                currentCriterion: $currentCriterion + 1,
            );

            $orderedEntryIds = [...$orderedEntryIds, ...$resolvedSubGroup['ordered_entry_ids']];
            $manualGroups = [...$manualGroups, ...$resolvedSubGroup['manual_groups']];
        }

        return [
            'ordered_entry_ids' => $orderedEntryIds,
            'manual_groups' => $manualGroups,
        ];
    }

    /**
     * @param  array<int, int>  $entryIds
     * @return array<int, int>
     */
    private function sortEntriesByName(array $entryIds, SinglesEntryIndex $index): array
    {
        usort($entryIds, function (int $leftEntryId, int $rightEntryId) use ($index): int {
            $leftName = strtolower($index->playerNameForEntry($leftEntryId));
            $rightName = strtolower($index->playerNameForEntry($rightEntryId));

            return [$leftName, $leftEntryId] <=> [$rightName, $rightEntryId];
        });

        return $entryIds;
    }
}
