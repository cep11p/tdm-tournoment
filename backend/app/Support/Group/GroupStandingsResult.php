<?php

namespace App\Support\Group;

use App\Data\Competition\CompetitionStandingData;
use Illuminate\Support\Collection;

final class GroupStandingsResult
{
    /**
     * @param  Collection<int, CompetitionStandingData>  $standings
     * @param  array<int, array<int, int>>  $pendingManualTieEntryGroups
     * @param  array<int, array{player_ids: array<int, int>, player_names: array<int, string>}>  $manualTiebreakGroups
     * @param  array<int, array{id: int, player_ids: array<int, int>, player_names: array<int, string>, reason: string, notes: ?string, applied_at: string}>  $appliedManualTiebreaks
     * @param  array<int, array{id: int, player_ids: array<int, int>, player_names: array<int, string>, reason: string, notes: ?string, applied_at: string}>  $staleManualTiebreaks
     */
    public function __construct(
        public readonly Collection $standings,
        public readonly array $pendingManualTieEntryGroups,
        public readonly array $manualTiebreakGroups = [],
        public readonly array $appliedManualTiebreaks = [],
        public readonly array $staleManualTiebreaks = [],
        public readonly bool $isProvisional = false,
        public readonly int $completedGamesCount = 0,
        public readonly int $totalGamesCount = 0,
        public readonly ?int $finishedTeamTiesCount = null,
        public readonly ?int $totalTeamTiesCount = null,
    ) {}

    public function requiresManualTiebreak(): bool
    {
        return ! $this->isProvisional && $this->pendingManualTieEntryGroups !== [];
    }

    public function hasManualTiebreaks(): bool
    {
        return $this->appliedManualTiebreaks !== [];
    }
}
