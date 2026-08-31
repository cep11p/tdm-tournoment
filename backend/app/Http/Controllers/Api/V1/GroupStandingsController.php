<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompetitionStanding\CompetitionStandingResource;
use App\Models\Group;
use App\Support\Group\GroupStandingsResolver;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GroupStandingsController extends Controller
{
    public function __construct(
        private readonly GroupStandingsResolver $groupStandingsResolver,
    ) {}

    public function index(Group $group): AnonymousResourceCollection
    {
        $result = $this->groupStandingsResolver->calculate($group);

        $meta = [
            'standings_are_provisional' => $result->isProvisional,
            'completed_games_count' => $result->completedGamesCount,
            'total_games_count' => $result->totalGamesCount,
            'requires_manual_tiebreak' => $result->requiresManualTiebreak(),
            'manual_tiebreak_groups' => $result->manualTiebreakGroups,
            'has_manual_tiebreaks' => $result->hasManualTiebreaks(),
            'manual_tiebreaks' => $result->appliedManualTiebreaks,
            'stale_manual_tiebreaks' => $result->staleManualTiebreaks,
        ];

        if ($result->finishedTeamTiesCount !== null) {
            $meta['finished_team_ties_count'] = $result->finishedTeamTiesCount;
            $meta['total_team_ties_count'] = $result->totalTeamTiesCount;
        }

        return CompetitionStandingResource::collection($result->standings)
            ->additional(['meta' => $meta]);
    }
}
