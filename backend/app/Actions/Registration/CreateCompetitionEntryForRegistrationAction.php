<?php

namespace App\Actions\Registration;

use App\Enums\CompetitionEntryStatus;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\CompetitionEntryMember;

final class CreateCompetitionEntryForRegistrationAction
{
    public function __invoke(Competition $competition, int $playerId): CompetitionEntry
    {
        $entry = CompetitionEntry::query()->create([
            'competition_id' => $competition->id,
            'status' => CompetitionEntryStatus::Active,
        ]);

        CompetitionEntryMember::query()->create([
            'competition_entry_id' => $entry->id,
            'competition_id' => $competition->id,
            'player_id' => $playerId,
            'member_order' => 1,
        ]);

        return $entry;
    }
}
