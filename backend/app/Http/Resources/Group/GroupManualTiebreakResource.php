<?php

namespace App\Http\Resources\Group;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroupManualTiebreakResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $playerIds = $this->orderedPlayerIds();
        $playerNames = $this->players
            ->map(fn ($entry): string => trim(sprintf(
                '%s %s',
                (string) $entry->player?->first_name,
                (string) $entry->player?->last_name
            )))
            ->values()
            ->all();

        return [
            'id' => $this->id,
            'group_id' => $this->group_id,
            'player_ids' => $playerIds,
            'player_names' => $playerNames,
            'reason' => $this->reason->value,
            'notes' => $this->notes,
            'applied_at' => $this->applied_at?->toIso8601String(),
        ];
    }
}
