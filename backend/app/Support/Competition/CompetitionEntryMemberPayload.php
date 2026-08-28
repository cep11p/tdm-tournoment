<?php

namespace App\Support\Competition;

use App\Models\CompetitionEntry;
use App\Models\CompetitionEntryMember;
use App\Models\Player;

final class CompetitionEntryMemberPayload
{
    /**
     * @return list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>
     */
    public static function forEntry(CompetitionEntry $entry): array
    {
        $entry->loadMissing('members.player');

        return $entry->members
            ->sortBy('member_order')
            ->values()
            ->map(fn (CompetitionEntryMember $member): array => self::forPlayer($member->player))
            ->all();
    }

    /**
     * @return array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}
     */
    public static function forPlayer(?Player $player): array
    {
        return [
            'id' => $player?->id,
            'first_name' => $player?->first_name,
            'last_name' => $player?->last_name,
            'nickname' => $player?->nickname,
        ];
    }
}
