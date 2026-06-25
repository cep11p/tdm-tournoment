<?php

namespace App\Support\Group;

use App\Data\Competition\CompetitionStandingData;
use Illuminate\Support\Collection;

final class GroupStandingsResult
{
    /**
     * @param  Collection<int, CompetitionStandingData>  $standings
     * @param  array<int, array{player_ids: array<int, int>, player_names: array<int, string>}>  $manualTiebreakGroups
     */
    public function __construct(
        public readonly Collection $standings,
        public readonly array $manualTiebreakGroups,
    ) {}

    public function requiresManualTiebreak(): bool
    {
        return $this->manualTiebreakGroups !== [];
    }
}
