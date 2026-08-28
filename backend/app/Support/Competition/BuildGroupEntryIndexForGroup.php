<?php

namespace App\Support\Competition;

use App\Enums\CompetitionType;
use App\Enums\GroupPlayerStatus;
use App\Models\CompetitionEntryMember;
use App\Models\Group;
use App\Models\GroupEntry;
use App\Models\Player;

final class BuildGroupEntryIndexForGroup
{
    public function __invoke(Group $group): GroupEntryIndex
    {
        $group->loadMissing('competition');

        $groupEntries = $group->groupEntries()
            ->with(['competitionEntry.members.player:id,first_name,last_name,nickname'])
            ->get();

        $competitionType = $group->competition?->type instanceof CompetitionType
            ? $group->competition->type
            : CompetitionType::Singles;
        $isSingles = $competitionType === CompetitionType::Singles;

        $entryIdByPlayerId = [];
        $playerIdByEntryId = [];
        $playerNameByEntryId = [];
        $displayNameByEntryId = [];
        $membersByEntryId = [];
        $statusByEntryId = [];

        foreach ($groupEntries as $groupEntry) {
            $entry = $groupEntry->competitionEntry;

            if ($entry === null) {
                continue;
            }

            $entryId = (int) $groupEntry->competition_entry_id;
            $status = $groupEntry->status ?? GroupPlayerStatus::Active;
            $members = CompetitionEntryMemberPayload::forEntry($entry);
            $displayName = CompetitionEntryDisplayName::for($entry);

            $displayNameByEntryId[$entryId] = $displayName;
            $membersByEntryId[$entryId] = $members;
            $statusByEntryId[$entryId] = $status;

            if ($isSingles) {
                $playerId = $this->resolveSinglesPlayerId($groupEntry);
                $playerName = $playerId !== null
                    ? $this->playerNameFromMembers($entry, $playerId)
                    : null;

                if ($playerId !== null) {
                    $entryIdByPlayerId[$playerId] = $entryId;
                    $playerIdByEntryId[$entryId] = $playerId;
                    $playerNameByEntryId[$entryId] = $playerName;
                }
            } else {
                $playerIdByEntryId[$entryId] = null;
                $playerNameByEntryId[$entryId] = null;
            }
        }

        return new GroupEntryIndex(
            isSingles: $isSingles,
            entryIdByPlayerId: $entryIdByPlayerId,
            playerIdByEntryId: $playerIdByEntryId,
            playerNameByEntryId: $playerNameByEntryId,
            displayNameByEntryId: $displayNameByEntryId,
            membersByEntryId: $membersByEntryId,
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

    private function playerNameFromMembers(\App\Models\CompetitionEntry $entry, int $playerId): ?string
    {
        $member = $entry->members->firstWhere('player_id', $playerId);

        if ($member === null) {
            return null;
        }

        $player = $member->player;

        if ($player instanceof Player) {
            $name = trim(sprintf('%s %s', $player->first_name, $player->last_name));

            return $name !== '' ? $name : null;
        }

        return null;
    }
}
