<?php

namespace App\Support\Group\Concerns;

use App\Data\Competition\CompetitionStandingData;
use App\Enums\GroupPlayerStatus;
use App\Models\Group;
use App\Models\GroupManualTiebreak;
use App\Support\Competition\GroupEntryIndex;
use App\Support\Group\GroupStandingsResult;
use Illuminate\Support\Collection;

trait AppliesGroupManualTiebreaks
{
    use RanksEntryTieGroups;

    /**
     * @param  array{
     *     stats_by_entry: array<int, array<string, int|float>>,
     *     ordered_entry_ids: array<int, int>,
     *     pending_manual_tie_entry_groups: array<int, array<int, int>>,
     *     index: GroupEntryIndex
     * }  $automatic
     * @param  array{
     *     is_complete: bool,
     *     is_provisional: bool,
     *     completed_games_count: int,
     *     total_games_count: int,
     *     finished_team_ties_count?: int,
     *     total_team_ties_count?: int,
     * }  $scheduleProgress
     */
    protected function applyPersistedManualTiebreaks(Group $group, array $automatic, array $scheduleProgress): GroupStandingsResult
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

        if (! $scheduleProgress['is_provisional']) {
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
            scheduleProgress: $scheduleProgress,
        );
    }

    /**
     * @param  array{
     *     stats_by_entry: array<int, array<string, int|float>>,
     *     ordered_entry_ids: array<int, int>,
     *     pending_manual_tie_entry_groups: array<int, array<int, int>>,
     *     index: GroupEntryIndex
     * }  $automatic
     * @param  array<int, array<string, mixed>>  $appliedManualTiebreaks
     * @param  array<int, array<string, mixed>>  $staleManualTiebreaks
     * @param  array<int, int>  $manualPositionByEntryId
     * @param  array<int, bool>  $appliedEntryFlags
     * @param  array{
     *     is_complete: bool,
     *     is_provisional: bool,
     *     completed_games_count: int,
     *     total_games_count: int,
     *     finished_team_ties_count?: int,
     *     total_team_ties_count?: int,
     * }  $scheduleProgress
     */
    protected function buildStandingsResult(
        array $automatic,
        array $appliedManualTiebreaks,
        array $staleManualTiebreaks,
        array $manualPositionByEntryId = [],
        array $appliedEntryFlags = [],
        array $scheduleProgress = [
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

                $rubbersWon = (int) ($stats['rubbers_won'] ?? 0);
                $rubbersLost = (int) ($stats['rubbers_lost'] ?? 0);
                $setsWon = (int) ($stats['sets_won'] ?? 0);
                $setsLost = (int) ($stats['sets_lost'] ?? 0);
                $pointsFor = (int) ($stats['points_for'] ?? 0);
                $pointsAgainst = (int) ($stats['points_against'] ?? 0);

                return new CompetitionStandingData(
                    competitionEntryId: $entryId,
                    displayName: $index->displayNameForEntry($entryId),
                    members: $index->membersForEntry($entryId),
                    playerId: $index->playerIdForEntry($entryId),
                    playerName: $index->playerNameForEntry($entryId),
                    won: (int) $stats['won'],
                    lost: (int) $stats['lost'],
                    rubbersWon: $rubbersWon,
                    rubbersLost: $rubbersLost,
                    rubberDifference: $rubbersWon - $rubbersLost,
                    setsWon: $setsWon,
                    setsLost: $setsLost,
                    setDifference: $setsWon - $setsLost,
                    pointsFor: $pointsFor,
                    pointsAgainst: $pointsAgainst,
                    pointDifference: $pointsFor - $pointsAgainst,
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
            fn (array $entryIds): array => $this->formatTiebreakGroupMeta($entryIds, $index),
            $pendingManualTieEntryGroups
        );

        return new GroupStandingsResult(
            standings: $standings,
            pendingManualTieEntryGroups: $pendingManualTieEntryGroups,
            manualTiebreakGroups: $manualTiebreakGroups,
            appliedManualTiebreaks: $appliedManualTiebreaks,
            staleManualTiebreaks: $staleManualTiebreaks,
            isProvisional: (bool) $scheduleProgress['is_provisional'],
            completedGamesCount: (int) $scheduleProgress['completed_games_count'],
            totalGamesCount: (int) $scheduleProgress['total_games_count'],
            finishedTeamTiesCount: isset($scheduleProgress['finished_team_ties_count'])
                ? (int) $scheduleProgress['finished_team_ties_count']
                : null,
            totalTeamTiesCount: isset($scheduleProgress['total_team_ties_count'])
                ? (int) $scheduleProgress['total_team_ties_count']
                : null,
        );
    }

    /**
     * @param  Collection<int, GroupManualTiebreak>  $persistedTiebreaks
     * @param  array<int, int>  $pendingGroup
     */
    protected function findMatchingTiebreak($persistedTiebreaks, array $pendingGroup): ?GroupManualTiebreak
    {
        foreach ($persistedTiebreaks as $tiebreak) {
            if ($this->entrySetsMatch($tiebreak->orderedCompetitionEntryIds(), $pendingGroup)) {
                return $tiebreak;
            }
        }

        return null;
    }

    /**
     * @param  array<int, int>  $entryIds
     * @return array{entry_ids: array<int, int>, display_names: array<int, string>, player_ids?: array<int, int>, player_names?: array<int, string>}
     */
    protected function formatTiebreakGroupMeta(array $entryIds, GroupEntryIndex $index): array
    {
        $meta = [
            'entry_ids' => array_values(array_map('intval', $entryIds)),
            'display_names' => $index->displayNamesForEntries($entryIds),
        ];

        if ($index->isSingles()) {
            $meta['player_ids'] = $index->playerIdsForEntries($entryIds);
            $meta['player_names'] = $index->playerNamesForEntries($entryIds);
        }

        return $meta;
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatPublicTiebreakRecord(GroupManualTiebreak $tiebreak, GroupEntryIndex $index): array
    {
        $entryIds = $tiebreak->orderedCompetitionEntryIds();

        return [
            'id' => (int) $tiebreak->id,
            ...$this->formatTiebreakGroupMeta($entryIds, $index),
            'reason' => $tiebreak->reason->value,
            'notes' => $tiebreak->notes,
            'applied_at' => $tiebreak->applied_at?->toIso8601String() ?? '',
        ];
    }
}
