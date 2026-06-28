<?php

namespace App\Data\Competition;

final class GroupQualifierData
{
    public function __construct(
        public int $playerId,
        public string $playerName,
        public int $groupId,
        public string $groupName,
        public int $groupPosition,
        public int $won,
        public int $lost,
    ) {}
}
