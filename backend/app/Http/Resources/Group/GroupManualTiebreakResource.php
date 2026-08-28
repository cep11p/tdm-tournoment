<?php

namespace App\Http\Resources\Group;

use App\Models\GroupManualTiebreakEntry;
use App\Support\Competition\CompetitionEntryDisplayName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroupManualTiebreakResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $entryIds = [];
        $displayNames = [];
        $playerIds = [];
        $playerNames = [];

        foreach ($this->entries as $tiebreakEntry) {
            /** @var GroupManualTiebreakEntry $tiebreakEntry */
            $entry = $tiebreakEntry->competitionEntry;
            $entryIds[] = (int) $tiebreakEntry->competition_entry_id;
            $displayNames[] = $entry !== null ? CompetitionEntryDisplayName::for($entry) : '';

            $members = $entry?->members;
            $member = $members?->firstWhere('member_order', 1) ?? $members?->first();
            $player = $member?->player;

            if ($member !== null && $entry?->members?->count() === 1) {
                $playerIds[] = (int) $member->player_id;
                $playerNames[] = trim(sprintf(
                    '%s %s',
                    (string) $player?->first_name,
                    (string) $player?->last_name
                ));
            }
        }

        $payload = [
            'id' => $this->id,
            'group_id' => $this->group_id,
            'entry_ids' => $entryIds,
            'display_names' => $displayNames,
            'reason' => $this->reason->value,
            'notes' => $this->notes,
            'applied_at' => $this->applied_at?->toIso8601String(),
        ];

        if ($playerIds !== []) {
            $payload['player_ids'] = $playerIds;
            $payload['player_names'] = $playerNames;
        }

        return $payload;
    }
}
