<?php

namespace App\Support\Group;

use App\Enums\CompetitionType;
use App\Models\Group;

final class GroupStandingsResolver
{
    public function __construct(
        private readonly GroupStandingsCalculator $groupStandingsCalculator,
        private readonly TeamGroupStandingsCalculator $teamGroupStandingsCalculator,
    ) {}

    public function calculate(Group $group): GroupStandingsResult
    {
        if ($this->isTeamGroup($group)) {
            return $this->teamGroupStandingsCalculator->calculate($group);
        }

        return $this->groupStandingsCalculator->calculate($group);
    }

    public function calculateAutomaticOnly(Group $group): GroupStandingsResult
    {
        if ($this->isTeamGroup($group)) {
            return $this->teamGroupStandingsCalculator->calculateAutomaticOnly($group);
        }

        return $this->groupStandingsCalculator->calculateAutomaticOnly($group);
    }

    public function isGroupComplete(Group $group): bool
    {
        if ($this->isTeamGroup($group)) {
            return $this->teamGroupStandingsCalculator->isGroupComplete($group);
        }

        return $this->groupStandingsCalculator->isGroupComplete($group);
    }

    private function isTeamGroup(Group $group): bool
    {
        $group->loadMissing('competition');

        $type = $group->competition?->type instanceof CompetitionType
            ? $group->competition->type
            : CompetitionType::tryFrom((string) $group->competition?->type);

        return $type === CompetitionType::Team;
    }
}
