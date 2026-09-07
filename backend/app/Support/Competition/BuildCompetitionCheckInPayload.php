<?php

namespace App\Support\Competition;

use App\Enums\CompetitionEntryStatus;
use App\Enums\CompetitionType;
use App\Enums\TournamentStatus;
use App\Models\Competition;
use App\Models\CompetitionEntryMember;
use App\Models\Player;

final class BuildCompetitionCheckInPayload
{
    /**
     * @return array{
     *     competition: array{id: int, name: string, type: string},
     *     tournament_finished: bool,
     *     summary: array{entries: int, members: int, checked_in_members: int, pending_members: int},
     *     entries: list<array<string, mixed>>,
     * }
     */
    public static function for(Competition $competition): array
    {
        $competition->load([
            'tournament:id,status',
            'entries' => fn ($query) => $query->orderBy('id'),
            'entries.members' => fn ($query) => $query->orderBy('member_order')->orderBy('id'),
            'entries.members.player:id,first_name,last_name,nickname',
        ]);

        $type = $competition->type instanceof CompetitionType
            ? $competition->type
            : CompetitionType::from((string) $competition->type);

        $tournamentStatus = $competition->tournament?->status;
        $tournamentFinished = $tournamentStatus instanceof TournamentStatus
            ? $tournamentStatus === TournamentStatus::Finished
            : (string) $tournamentStatus === TournamentStatus::Finished->value;

        $entryPayloads = [];
        $checkedInMembers = 0;
        $pendingMembers = 0;
        $memberCount = 0;

        foreach ($competition->entries as $entry) {
            $status = $entry->status instanceof CompetitionEntryStatus
                ? $entry->status
                : CompetitionEntryStatus::from((string) $entry->status);
            $isActive = $status === CompetitionEntryStatus::Active;

            $members = $entry->members
                ->map(fn (CompetitionEntryMember $member): array => self::memberPayload($member))
                ->values()
                ->all();

            foreach ($members as $member) {
                $memberCount++;

                if (! $isActive) {
                    continue;
                }

                if ($member['checked_in']) {
                    $checkedInMembers++;
                } else {
                    $pendingMembers++;
                }
            }

            $entryPayloads[] = [
                'id' => (int) $entry->id,
                'display_name' => CompetitionEntryDisplayName::for($entry),
                'status' => $status->value,
                'members' => $members,
                'availability' => CompetitionEntryAvailabilityResolver::forEntry($entry, $type),
            ];
        }

        return [
            'competition' => [
                'id' => (int) $competition->id,
                'name' => (string) $competition->name,
                'type' => $type->value,
            ],
            'tournament_finished' => $tournamentFinished,
            'summary' => [
                'entries' => count($entryPayloads),
                'members' => $memberCount,
                'checked_in_members' => $checkedInMembers,
                'pending_members' => $pendingMembers,
            ],
            'entries' => $entryPayloads,
        ];
    }

    /**
     * @return array{
     *     id: int,
     *     player_id: int|null,
     *     first_name: string|null,
     *     last_name: string|null,
     *     nickname: string|null,
     *     checked_in: bool,
     *     checked_in_at: string|null,
     * }
     */
    public static function memberPayload(CompetitionEntryMember $member): array
    {
        $player = $member->relationLoaded('player')
            ? $member->player
            : $member->player()->first();

        return [
            'id' => (int) $member->id,
            'player_id' => $player instanceof Player ? (int) $player->id : (int) $member->player_id,
            'first_name' => $player?->first_name,
            'last_name' => $player?->last_name,
            'nickname' => $player?->nickname,
            'checked_in' => $member->isCheckedIn(),
            'checked_in_at' => $member->checked_in_at?->toISOString(),
        ];
    }
}
