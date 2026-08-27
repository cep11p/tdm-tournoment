<?php

namespace App\Support\Competition;

use App\Enums\GroupPlayerStatus;

final class SinglesEntryIndex
{
    /**
     * @param  array<int, int>  $entryIdByPlayerId
     * @param  array<int, int>  $playerIdByEntryId
     * @param  array<int, string>  $playerNameByEntryId
     * @param  array<int, GroupPlayerStatus>  $statusByEntryId
     */
    public function __construct(
        private readonly array $entryIdByPlayerId,
        private readonly array $playerIdByEntryId,
        private readonly array $playerNameByEntryId,
        private readonly array $statusByEntryId,
    ) {}

    /**
     * @return list<int>
     */
    public function entryIds(): array
    {
        return array_keys($this->playerIdByEntryId);
    }

    public function entryIdForPlayer(int $playerId): ?int
    {
        return $this->entryIdByPlayerId[$playerId] ?? null;
    }

    public function playerIdForEntry(int $entryId): ?int
    {
        return $this->playerIdByEntryId[$entryId] ?? null;
    }

    public function playerNameForEntry(int $entryId): string
    {
        return $this->playerNameByEntryId[$entryId] ?? '';
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
        return array_values(array_map(
            fn (int $entryId): int => (int) ($this->playerIdByEntryId[$entryId] ?? 0),
            $entryIds
        ));
    }

    /**
     * @param  array<int, int>  $entryIds
     * @return array<int, string>
     */
    public function playerNamesForEntries(array $entryIds): array
    {
        return array_values(array_map(
            fn (int $entryId): string => $this->playerNameForEntry($entryId),
            $entryIds
        ));
    }
}
