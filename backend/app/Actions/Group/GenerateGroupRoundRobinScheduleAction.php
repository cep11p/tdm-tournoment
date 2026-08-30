<?php

namespace App\Actions\Group;

use App\Enums\CompetitionType;
use App\Models\Game;
use App\Models\Group;
use App\Models\TeamTie;
use Illuminate\Support\Collection;

final class GenerateGroupRoundRobinScheduleAction
{
    public function __construct(
        private readonly GenerateGroupRoundRobinGamesAction $generateGames,
        private readonly GenerateGroupRoundRobinTeamTiesAction $generateTeamTies,
    ) {}

    /**
     * @return Collection<int, Game|TeamTie>
     */
    public function __invoke(Group $group): Collection
    {
        $group->loadMissing('competition');

        $type = $group->competition->type instanceof CompetitionType
            ? $group->competition->type
            : CompetitionType::from((string) $group->competition->type);

        if ($type === CompetitionType::Team) {
            return ($this->generateTeamTies)($group);
        }

        return ($this->generateGames)($group);
    }
}
