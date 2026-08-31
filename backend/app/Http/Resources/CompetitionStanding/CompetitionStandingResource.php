<?php

namespace App\Http\Resources\CompetitionStanding;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompetitionStandingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'competition_entry_id' => $this->competitionEntryId,
            'display_name' => $this->displayName,
            'members' => $this->members,
            'player_id' => $this->playerId,
            'player_name' => $this->playerName,
            'played' => $this->played(),
            'won' => $this->won,
            'lost' => $this->lost,
            'rubbers_won' => $this->rubbersWon,
            'rubbers_lost' => $this->rubbersLost,
            'rubber_difference' => $this->rubberDifference,
            'sets_won' => $this->setsWon,
            'sets_lost' => $this->setsLost,
            'set_difference' => $this->setDifference,
            'points_for' => $this->pointsFor,
            'points_against' => $this->pointsAgainst,
            'point_difference' => $this->pointDifference,
            'requires_manual_tiebreak' => (bool) ($this->requiresManualTiebreak ?? false),
            'manual_tiebreak_applied' => (bool) ($this->manualTiebreakApplied ?? false),
            'manual_position' => $this->manualPosition,
            'eligible_for_qualification' => (bool) ($this->eligibleForQualification ?? true),
            'group_player_status' => $this->groupPlayerStatus ?? 'active',
        ];
    }
}
