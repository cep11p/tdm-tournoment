<?php

namespace App\Support\Competition;

use App\Enums\GroupPlayerStatus;

final class GroupEntryIndex
{
    /**
     * @param  array<int, int>  $entryIdByPlayerId
     * @param  array<int, int|null>  $playerIdByEntryId
     * @param  array<int, string|null>  $playerNameByEntryId
     * @param  array<int, string>  $displayNameByEntryId
     * @param  array<int, list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>>  $membersByEntryId
     * @param  array<int, GroupPlayerStatus>  $statusByEntryId
     */
    public function __construct(
        private readonly bool $isSingles,
        private readonly array $entryIdByPlayerId,
        private readonly array $playerIdByEntryId,
        private readonly array $playerNameByEntryId,
        private readonly array $displayNameByEntryId,
        private readonly array $membersByEntryId,
        private readonly array $statusByEntryId,
    ) {}

    public function isSingles(): bool
    {
        return $this->isSingles;
    }

    /**
     * @return list<int>
     */
    public function entryIds(): array
    {
        return array_keys($this->displayNameByEntryId);
    }

    public function entryIdForPlayer(int $playerId): ?int
    {
        return $this->entryIdByPlayerId[$playerId] ?? null;
    }

    public function playerIdForEntry(int $entryId): ?int
    {
        return $this->playerIdByEntryId[$entryId] ?? null;
    }

    public function playerNameForEntry(int $entryId): ?string
    {
        return $this->playerNameByEntryId[$entryId] ?? null;
    }

    public function displayNameForEntry(int $entryId): string
    {
        return $this->displayNameByEntryId[$entryId] ?? '';
    }

    /**
     * @return list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>
     */
    public function membersForEntry(int $entryId): array
    {
        return $this->membersByEntryId[$entryId] ?? [];
    }

    public function statusForEntry(int $entryId): GroupPlayerStatus
    {
        return $this->statusByEntryId[$entryId] ?? GroupPlayerStatus::Active;
    }

    public function hasPlayer(int $playerId): bool
    {
        return isset($this->entryIdByPlayerId[$playerId]);
    }

    /**
     * @param  array<int, int>  $entryIds
     * @return array<int, int>
     */
    public function playerIdsForEntries(array $entryIds): array
    {
        return array_values(array_filter(array_map(
            fn (int $entryId): ?int => $this->playerIdForEntry($entryId),
            $entryIds
        )));
    }

    /**
     * @param  array<int, int>  $entryIds
     * @return array<int, string>
     */
    public function playerNamesForEntries(array $entryIds): array
    {
        return array_values(array_filter(array_map(
            fn (int $entryId): ?string => $this->playerNameForEntry($entryId),
            $entryIds
        )));
    }

    /**
     * @param  array<int, int>  $entryIds
     * @return array<int, string>
     */
    public function displayNamesForEntries(array $entryIds): array
    {
        return array_values(array_map(
            fn (int $entryId): string => $this->displayNameForEntry($entryId),
            $entryIds
        ));
    }
}
