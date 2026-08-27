<?php

namespace App\Http\Resources\Group;

use App\Models\GroupManualTiebreakEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroupManualTiebreakResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $playerIds = [];
        $playerNames = [];

        foreach ($this->entries as $tiebreakEntry) {
            /** @var GroupManualTiebreakEntry $tiebreakEntry */
            $members = $tiebreakEntry->competitionEntry?->members;
            $member = $members?->firstWhere('member_order', 1) ?? $members?->first();
            $player = $member?->player;

            $playerIds[] = (int) ($member?->player_id ?? 0);
            $playerNames[] = trim(sprintf(
                '%s %s',
                (string) $player?->first_name,
                (string) $player?->last_name
            ));
        }

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
