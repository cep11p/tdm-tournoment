<?php

namespace App\Http\Resources\Game;

use App\Models\CompetitionEntry;
use App\Support\Competition\CompetitionEntryDisplayName;
use App\Support\Competition\CompetitionEntryMemberPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompetitionEntrySideResource extends JsonResource
{
    public function toArray(Request $request): ?array
    {
        if ($this->resource === null) {
            return null;
        }

        /** @var CompetitionEntry $entry */
        $entry = $this->resource;
        $entry->loadMissing('members.player');

        return [
            'competition_entry_id' => $entry->id,
            'display_name' => CompetitionEntryDisplayName::for($entry),
            'members' => CompetitionEntryMemberPayload::forEntry($entry),
        ];
    }
}
