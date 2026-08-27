<?php

namespace App\Support\Competition;

use App\Enums\GroupPlayerStatus;
use App\Models\Group;
use App\Models\GroupEntry;

final class BuildSinglesEntryIndexForGroup
{
    public function __invoke(Group $group): SinglesEntryIndex
    {
        $groupEntries = $group->groupEntries()
            ->with(['competitionEntry.members.player:id,first_name,last_name'])
            ->get();

        $entryIdByPlayerId = [];
        $playerIdByEntryId = [];
        $playerNameByEntryId = [];
        $statusByEntryId = [];

        foreach ($groupEntries as $groupEntry) {
            $playerId = $this->resolveSinglesPlayerId($groupEntry);

            if ($playerId === null) {
                continue;
            }

            $entryId = (int) $groupEntry->competition_entry_id;
            $status = $groupEntry->status ?? GroupPlayerStatus::Active;
            $player = $groupEntry->competitionEntry?->members
                ->firstWhere('player_id', $playerId)
                ?->player;

            $entryIdByPlayerId[$playerId] = $entryId;
            $playerIdByEntryId[$entryId] = $playerId;
            $playerNameByEntryId[$entryId] = trim(sprintf(
                '%s %s',
                (string) $player?->first_name,
                (string) $player?->last_name
            ));
            $statusByEntryId[$entryId] = $status;
        }

        return new SinglesEntryIndex(
            entryIdByPlayerId: $entryIdByPlayerId,
            playerIdByEntryId: $playerIdByEntryId,
            playerNameByEntryId: $playerNameByEntryId,
            statusByEntryId: $statusByEntryId,
        );
    }

    private function resolveSinglesPlayerId(GroupEntry $groupEntry): ?int
    {
        $members = $groupEntry->competitionEntry?->members;

        if ($members === null || $members->isEmpty()) {
            return null;
        }

        $member = $members->firstWhere('member_order', 1) ?? $members->first();

        if ($member === null) {
            return null;
        }

        return (int) $member->player_id;
    }
}
