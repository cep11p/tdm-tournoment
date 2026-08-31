<?php

namespace App\Support\Group;

use App\Enums\GameStatus;
use App\Enums\GroupPlayerStatus;
use App\Models\Game;
use App\Models\Group;
use App\Support\Competition\BuildGroupEntryIndexForGroup;
use App\Support\Competition\GroupEntryIndex;
use App\Support\Group\Concerns\AppliesGroupManualTiebreaks;

final class GroupStandingsCalculator
{
    use AppliesGroupManualTiebreaks;

    public function __construct(
        private readonly BuildGroupEntryIndexForGroup $buildGroupEntryIndexForGroup,
    ) {}

    public function calculate(Group $group): GroupStandingsResult
    {
        $scheduleProgress = $this->resolveGroupGamesProgress($group);
        $automatic = $this->calculateAutomatic($group);

        if ($scheduleProgress['is_provisional']) {
            $automatic['pending_manual_tie_entry_groups'] = [];
        }

        return $this->applyPersistedManualTiebreaks($group, $automatic, $scheduleProgress);
    }

    public function calculateAutomaticOnly(Group $group): GroupStandingsResult
    {
        $scheduleProgress = $this->resolveGroupGamesProgress($group);
        $automatic = $this->calculateAutomatic($group);

        if ($scheduleProgress['is_provisional']) {
            $automatic['pending_manual_tie_entry_groups'] = [];
        }

        return $this->buildStandingsResult(
            automatic: $automatic,
            appliedManualTiebreaks: [],
            staleManualTiebreaks: [],
            scheduleProgress: $scheduleProgress,
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
     *     index: GroupEntryIndex
     * }
     */
    private function calculateAutomatic(Group $group): array
    {
        $index = ($this->buildGroupEntryIndexForGroup)($group);
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
        GroupEntryIndex $index,
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
}
