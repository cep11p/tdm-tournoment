<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompetitionStanding\CompetitionStandingResource;
use App\Models\Group;
use App\Support\Group\GroupStandingsCalculator;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GroupStandingsController extends Controller
{
    public function __construct(
        private readonly GroupStandingsCalculator $groupStandingsCalculator,
    ) {}

    public function index(Group $group): AnonymousResourceCollection
    {
        $result = $this->groupStandingsCalculator->calculate($group);

        return CompetitionStandingResource::collection($result->standings)
            ->additional([
                'meta' => [
                    'requires_manual_tiebreak' => $result->requiresManualTiebreak(),
                    'manual_tiebreak_groups' => $result->manualTiebreakGroups,
                    'has_manual_tiebreaks' => $result->hasManualTiebreaks(),
                    'manual_tiebreaks' => $result->appliedManualTiebreaks,
                    'stale_manual_tiebreaks' => $result->staleManualTiebreaks,
                ],
            ]);
    }
}
