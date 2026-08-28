<?php

namespace App\Support\Competition;

use App\Models\CompetitionEntry;
use App\Models\Player;

final class CompetitionEntryDisplayName
{
    public static function for(CompetitionEntry $entry): string
    {
        $entry->loadMissing('members.player');

        $names = $entry->members
            ->sortBy('member_order')
            ->map(fn ($member) => self::playerName($member->player))
            ->filter()
            ->values();

        return $names->implode(' / ');
    }

    private static function playerName(?Player $player): ?string
    {
        if ($player === null) {
            return null;
        }

        $name = trim(sprintf('%s %s', $player->first_name, $player->last_name));

        return $name !== '' ? $name : null;
    }
}
