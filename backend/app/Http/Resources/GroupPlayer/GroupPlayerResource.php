<?php

namespace App\Http\Resources\GroupPlayer;

use App\Enums\CompetitionType;
use App\Enums\GroupPlayerStatus;
use App\Models\GroupEntry;
use App\Support\Competition\CompetitionEntryDisplayName;
use App\Support\Competition\CompetitionEntryMemberPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroupPlayerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var GroupEntry $groupEntry */
        $groupEntry = $this->resource;
        $entry = $groupEntry->competitionEntry;
        $entry?->loadMissing(['members.player', 'competition']);
        $status = $groupEntry->status ?? GroupPlayerStatus::Active;

        $type = $entry?->competition?->type instanceof CompetitionType
            ? $entry->competition->type
            : CompetitionType::Singles;
        $isSingles = $type === CompetitionType::Singles;
        $members = $entry !== null ? CompetitionEntryMemberPayload::forEntry($entry) : [];
        $displayName = $entry !== null ? CompetitionEntryDisplayName::for($entry) : '';

        $player = $isSingles ? ($members[0] ?? null) : null;
        $playerId = $player['id'] ?? null;
        $playerName = $isSingles && $player !== null
            ? trim(sprintf('%s %s', (string) $player['first_name'], (string) $player['last_name']))
            : null;

        return [
            'id' => $groupEntry->id,
            'group_id' => $groupEntry->group_id,
            'competition_entry_id' => $entry?->id,
            'display_name' => $displayName,
            'members' => $members,
            'player_id' => $playerId,
            'player_name' => $playerName !== '' ? $playerName : null,
            'player' => $player,
            'status' => $status->value,
            'status_reason' => $groupEntry->status_reason?->value,
            'status_notes' => $groupEntry->status_notes,
            'status_changed_at' => $groupEntry->status_changed_at?->toIso8601String(),
            'created_at' => optional($groupEntry->created_at)->toISOString(),
            'updated_at' => optional($groupEntry->updated_at)->toISOString(),
        ];
    }
}
