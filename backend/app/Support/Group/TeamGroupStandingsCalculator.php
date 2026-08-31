<?php

namespace App\Support\Group;

use App\Enums\GroupPlayerStatus;
use App\Enums\TeamTieStatus;
use App\Models\Game;
use App\Models\Group;
use App\Models\TeamTie;
use App\Support\Competition\BuildGroupEntryIndexForGroup;
use App\Support\Competition\GroupEntryIndex;
use App\Support\Group\Concerns\AppliesGroupManualTiebreaks;
use App\Support\TeamTie\TeamTieOutcomeResolver;
use Illuminate\Support\Collection;

final class TeamGroupStandingsCalculator
{
    use AppliesGroupManualTiebreaks;

    public function __construct(
        private readonly BuildGroupEntryIndexForGroup $buildGroupEntryIndexForGroup,
    ) {}

    public function calculate(Group $group): GroupStandingsResult
    {
        $scheduleProgress = $this->resolveTeamTiesProgress($group);
        $automatic = $this->calculateAutomatic($group);

        if ($scheduleProgress['is_provisional']) {
            $automatic['pending_manual_tie_entry_groups'] = [];
        }

        return $this->applyPersistedManualTiebreaks($group, $automatic, $scheduleProgress);
    }

    public function calculateAutomaticOnly(Group $group): GroupStandingsResult
    {
        $scheduleProgress = $this->resolveTeamTiesProgress($group);
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
        return $this->resolveTeamTiesProgress($group)['is_complete'];
    }

    /**
     * @return array{
     *     is_complete: bool,
     *     is_provisional: bool,
     *     completed_games_count: int,
     *     total_games_count: int,
     *     finished_team_ties_count: int,
     *     total_team_ties_count: int,
     * }
     */
    private function resolveTeamTiesProgress(Group $group): array
    {
        $totalTeamTiesCount = $group->teamTies()->count();

        if ($totalTeamTiesCount === 0) {
            return [
                'is_complete' => false,
                'is_provisional' => true,
                'completed_games_count' => 0,
                'total_games_count' => 0,
                'finished_team_ties_count' => 0,
                'total_team_ties_count' => 0,
            ];
        }

        $finishedTeamTiesCount = $group->teamTies()
            ->where('status', TeamTieStatus::Finished)
            ->count();

        $hasUnfinishedTeamTies = $group->teamTies()
            ->where('status', '!=', TeamTieStatus::Finished)
            ->exists();

        return [
            'is_complete' => ! $hasUnfinishedTeamTies,
            'is_provisional' => $hasUnfinishedTeamTies,
            'completed_games_count' => $finishedTeamTiesCount,
            'total_games_count' => $totalTeamTiesCount,
            'finished_team_ties_count' => $finishedTeamTiesCount,
            'total_team_ties_count' => $totalTeamTiesCount,
        ];
    }

    /**
     * @return Collection<int, TeamTie>
     */
    private function loadGroupTeamTies(Group $group): Collection
    {
        return $group->teamTies()
            ->with([
                'teamTieGames' => fn ($query) => $query->orderBy('slot_order'),
                'teamTieGames.game.sets',
            ])
            ->get();
    }

    /**
     * @return array{
     *     stats_by_entry: array<int, array<string, int>>,
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
            $statsByEntry[$entryId] = $this->emptyEntryStats();
        }

        $teamTies = $this->loadGroupTeamTies($group);

        $finishedTeamTies = $teamTies
            ->filter(function (TeamTie $teamTie): bool {
                $status = $teamTie->status instanceof TeamTieStatus
                    ? $teamTie->status
                    : TeamTieStatus::from((string) $teamTie->status);

                return $status === TeamTieStatus::Finished
                    && $teamTie->winner_entry_id !== null;
            })
            ->values();

        foreach ($finishedTeamTies as $teamTie) {
            $winnerEntryId = (int) $teamTie->winner_entry_id;
            $entry1Id = (int) $teamTie->entry1_id;
            $entry2Id = (int) $teamTie->entry2_id;
            $loserEntryId = $winnerEntryId === $entry1Id ? $entry2Id : $entry1Id;

            if (isset($statsByEntry[$winnerEntryId])) {
                $statsByEntry[$winnerEntryId]['won']++;
            }

            if (isset($statsByEntry[$loserEntryId])) {
                $statsByEntry[$loserEntryId]['lost']++;
            }

            foreach (TeamTieOutcomeResolver::officialRubbers($teamTie) as $officialRubber) {
                $this->accumulateRubberStats($statsByEntry, $officialRubber);
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

            $resolvedTie = $this->resolveTeamTieGroup(
                tiedEntryIds: $tiedEntryIds,
                finishedTeamTies: $finishedTeamTies->all(),
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
     * @return array<string, int>
     */
    private function emptyEntryStats(): array
    {
        return [
            'won' => 0,
            'lost' => 0,
            'rubbers_won' => 0,
            'rubbers_lost' => 0,
            'sets_won' => 0,
            'sets_lost' => 0,
            'points_for' => 0,
            'points_against' => 0,
        ];
    }

    /**
     * @param  array<int, array<string, int>>  $statsByEntry
     * @param  array{
     *     slot_order: int,
     *     team_tie_game: \App\Models\TeamTieGame,
     *     game: Game,
     *     winner_entry_id: int,
     *     entry1_id: int,
     *     entry2_id: int,
     * }  $officialRubber
     */
    private function accumulateRubberStats(array &$statsByEntry, array $officialRubber): void
    {
        $game = $officialRubber['game'];
        $entry1Id = (int) $officialRubber['entry1_id'];
        $entry2Id = (int) $officialRubber['entry2_id'];
        $winnerId = (int) $officialRubber['winner_entry_id'];
        $loserId = $winnerId === $entry1Id ? $entry2Id : $entry1Id;

        if (isset($statsByEntry[$winnerId])) {
            $statsByEntry[$winnerId]['rubbers_won']++;
        }

        if (isset($statsByEntry[$loserId])) {
            $statsByEntry[$loserId]['rubbers_lost']++;
        }

        $setsWon = $game->setsWonCount($game->sets);

        if (isset($statsByEntry[$entry1Id])) {
            $statsByEntry[$entry1Id]['sets_won'] += $setsWon['player1'];
            $statsByEntry[$entry1Id]['sets_lost'] += $setsWon['player2'];

            foreach ($game->sets as $set) {
                $statsByEntry[$entry1Id]['points_for'] += (int) $set->player1_score;
                $statsByEntry[$entry1Id]['points_against'] += (int) $set->player2_score;
            }
        }

        if (isset($statsByEntry[$entry2Id])) {
            $statsByEntry[$entry2Id]['sets_won'] += $setsWon['player2'];
            $statsByEntry[$entry2Id]['sets_lost'] += $setsWon['player1'];

            foreach ($game->sets as $set) {
                $statsByEntry[$entry2Id]['points_for'] += (int) $set->player2_score;
                $statsByEntry[$entry2Id]['points_against'] += (int) $set->player1_score;
            }
        }
    }

    /**
     * @param  array<int, int>  $tiedEntryIds
     * @param  array<int, TeamTie>  $finishedTeamTies
     * @return array{
     *     ordered_entry_ids: array<int, int>,
     *     manual_groups: array<int, array<int, int>>
     * }
     */
    private function resolveTeamTieGroup(
        array $tiedEntryIds,
        array $finishedTeamTies,
        GroupEntryIndex $index,
    ): array {
        $tiedEntryLookup = array_fill_keys($tiedEntryIds, true);
        $miniStats = [];

        foreach ($tiedEntryIds as $entryId) {
            $miniStats[$entryId] = [
                'mini_tie_won' => 0,
                'mini_rubber_diff' => 0,
                'mini_set_diff' => 0,
                'mini_point_diff' => 0,
            ];
        }

        foreach ($finishedTeamTies as $teamTie) {
            $entry1Id = (int) $teamTie->entry1_id;
            $entry2Id = (int) $teamTie->entry2_id;

            if (! isset($tiedEntryLookup[$entry1Id], $tiedEntryLookup[$entry2Id])) {
                continue;
            }

            $winnerEntryId = (int) $teamTie->winner_entry_id;

            if (isset($miniStats[$winnerEntryId])) {
                $miniStats[$winnerEntryId]['mini_tie_won']++;
            }

            foreach (TeamTieOutcomeResolver::officialRubbers($teamTie) as $officialRubber) {
                $this->accumulateMiniRubberStats($miniStats, $officialRubber);
            }
        }

        return $this->rankByMiniCriteria(
            entryIds: $tiedEntryIds,
            miniStats: $miniStats,
            index: $index,
            criteria: ['mini_tie_won', 'mini_rubber_diff', 'mini_set_diff', 'mini_point_diff'],
            currentCriterion: 0,
        );
    }

    /**
     * @param  array<int, array<string, int>>  $miniStats
     * @param  array{
     *     slot_order: int,
     *     team_tie_game: \App\Models\TeamTieGame,
     *     game: Game,
     *     winner_entry_id: int,
     *     entry1_id: int,
     *     entry2_id: int,
     * }  $officialRubber
     */
    private function accumulateMiniRubberStats(array &$miniStats, array $officialRubber): void
    {
        $game = $officialRubber['game'];
        $entry1Id = (int) $officialRubber['entry1_id'];
        $entry2Id = (int) $officialRubber['entry2_id'];
        $winnerId = (int) $officialRubber['winner_entry_id'];
        $loserId = $winnerId === $entry1Id ? $entry2Id : $entry1Id;

        if (isset($miniStats[$winnerId])) {
            $miniStats[$winnerId]['mini_rubber_diff']++;
        }

        if (isset($miniStats[$loserId])) {
            $miniStats[$loserId]['mini_rubber_diff']--;
        }

        $setsWon = $game->setsWonCount($game->sets);
        $entry1SetDiff = $setsWon['player1'] - $setsWon['player2'];
        $entry2SetDiff = -$entry1SetDiff;

        $entry1Points = 0;
        $entry2Points = 0;

        foreach ($game->sets as $set) {
            $entry1Points += (int) $set->player1_score;
            $entry2Points += (int) $set->player2_score;
        }

        $entry1PointDiff = $entry1Points - $entry2Points;
        $entry2PointDiff = -$entry1PointDiff;

        if (isset($miniStats[$entry1Id])) {
            $miniStats[$entry1Id]['mini_set_diff'] += $entry1SetDiff;
            $miniStats[$entry1Id]['mini_point_diff'] += $entry1PointDiff;
        }

        if (isset($miniStats[$entry2Id])) {
            $miniStats[$entry2Id]['mini_set_diff'] += $entry2SetDiff;
            $miniStats[$entry2Id]['mini_point_diff'] += $entry2PointDiff;
        }
    }
}
