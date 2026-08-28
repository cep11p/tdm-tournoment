<?php

namespace App\Data\Competition;

final class GroupQualifierData
{
    /**
     * @param  list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>  $members
     */
    public function __construct(
        public int $competitionEntryId,
        public string $displayName,
        public array $members,
        public ?int $playerId,
        public ?string $playerName,
        public int $groupId,
        public string $groupName,
        public int $groupPosition,
        public int $won,
        public int $lost,
    ) {}
}
