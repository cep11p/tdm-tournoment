<?php

namespace App\Support\Competition;

use App\Enums\CompetitionType;
use App\Models\Competition;
use App\Models\CompetitionEntry;

final class CompetitionEntrySummaryPayload
{
    /**
     * @return array{
     *     competition_entry_id: int,
     *     display_name: string,
     *     members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>,
     *     id: int|null,
     *     name: string|null,
     * }|null
     */
    public static function forEntry(CompetitionEntry $entry, Competition $competition): ?array
    {
        $entry->loadMissing('members.player');

        $displayName = CompetitionEntryDisplayName::for($entry);

        if ($displayName === '') {
            return null;
        }

        $members = CompetitionEntryMemberPayload::forEntry($entry);
        $type = $competition->type instanceof CompetitionType
            ? $competition->type
            : CompetitionType::from((string) $competition->type);

        $legacyId = null;
        $legacyName = null;

        if ($type === CompetitionType::Singles) {
            $player = $members[0] ?? null;
            $legacyId = $player['id'] ?? null;
            $legacyName = self::playerDisplayName($player);
        }

        return [
            'competition_entry_id' => (int) $entry->id,
            'display_name' => $displayName,
            'members' => $members,
            'id' => $legacyId,
            'name' => $legacyName,
        ];
    }

    /**
     * @return array{
     *     competition_entry_id: int,
     *     display_name: string,
     *     members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>,
     * }
     */
    public static function forEntrySide(CompetitionEntry $entry): array
    {
        $entry->loadMissing('members.player');

        return [
            'competition_entry_id' => (int) $entry->id,
            'display_name' => CompetitionEntryDisplayName::for($entry),
            'members' => CompetitionEntryMemberPayload::forEntry($entry),
        ];
    }

    /**
     * @param  array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}|null  $player
     */
    private static function playerDisplayName(?array $player): ?string
    {
        if ($player === null || $player['id'] === null) {
            return null;
        }

        $name = trim(sprintf('%s %s', (string) $player['first_name'], (string) $player['last_name']));

        return $name !== '' ? $name : null;
    }
}
