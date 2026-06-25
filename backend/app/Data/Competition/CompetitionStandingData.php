<?php

namespace App\Data\Competition;

final class CompetitionStandingData
{
    public function __construct(
        public int $playerId,
        public string $playerName,
        public int $won,
        public int $lost,
        public bool $requiresManualTiebreak = false,
        public bool $manualTiebreakApplied = false,
        public ?int $manualPosition = null,
    ) {
    }

    public function played(): int
    {
        return $this->won + $this->lost;
    }
}
