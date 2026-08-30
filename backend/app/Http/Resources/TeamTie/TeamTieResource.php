<?php

namespace App\Http\Resources\TeamTie;

use App\Enums\TeamTieStatus;
use App\Http\Resources\Game\CompetitionEntrySideResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamTieResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof TeamTieStatus
            ? $this->status->value
            : (string) $this->status;

        return [
            'id' => $this->id,
            'competition_id' => $this->competition_id,
            'group_id' => $this->group_id,
            'status' => $status,
            'is_bye' => (bool) $this->is_bye,
            'entry1' => new CompetitionEntrySideResource($this->whenLoaded('entry1')),
            'entry2' => new CompetitionEntrySideResource($this->whenLoaded('entry2')),
            'winner_entry_id' => $this->winner_entry_id,
            'format' => [
                'id' => $this->team_tie_format_id,
                'name' => $this->format_name,
                'victories_required' => $this->victories_required,
            ],
            'group_round' => $this->group_round,
            'group_match' => $this->group_match,
            'finished_at' => optional($this->finished_at)->toISOString(),
            'created_at' => optional($this->created_at)->toISOString(),
            'updated_at' => optional($this->updated_at)->toISOString(),
        ];
    }
}
