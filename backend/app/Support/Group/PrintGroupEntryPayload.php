<?php

namespace App\Support\Group;

use App\Models\CompetitionEntry;
use App\Support\Competition\CompetitionEntryDisplayName;
use App\Support\Competition\CompetitionEntryMemberPayload;

final class PrintGroupEntryPayload
{
    /**
     * @return array{
     *     competition_entry_id: int,
     *     sheet_number: int|null,
     *     display_name: string,
     *     members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>
     * }|null
     */
    public static function for(?CompetitionEntry $entry, ?int $sheetNumber = null): ?array
    {
        if ($entry === null) {
            return null;
        }

        $entry->loadMissing('members.player');

        return [
            'competition_entry_id' => (int) $entry->id,
            'sheet_number' => $sheetNumber,
            'display_name' => CompetitionEntryDisplayName::for($entry),
            'members' => CompetitionEntryMemberPayload::forEntry($entry),
        ];
    }
}
