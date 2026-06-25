<?php

namespace App\Http\Resources\CompetitionStanding;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompetitionStandingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'player_id' => $this->playerId,
            'player_name' => $this->playerName,
            'played' => $this->played(),
            'won' => $this->won,
            'lost' => $this->lost,
            'requires_manual_tiebreak' => (bool) ($this->requiresManualTiebreak ?? false),
            'manual_tiebreak_applied' => (bool) ($this->manualTiebreakApplied ?? false),
            'manual_position' => $this->manualPosition,
            'eligible_for_qualification' => (bool) ($this->eligibleForQualification ?? true),
            'group_player_status' => $this->groupPlayerStatus ?? 'active',
        ];
    }
}
