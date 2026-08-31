<?php

namespace App\Data\Competition;

final class CompetitionStandingData
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
        public int $won,
        public int $lost,
        public int $rubbersWon = 0,
        public int $rubbersLost = 0,
        public int $rubberDifference = 0,
        public int $setsWon = 0,
        public int $setsLost = 0,
        public int $setDifference = 0,
        public int $pointsFor = 0,
        public int $pointsAgainst = 0,
        public int $pointDifference = 0,
        public bool $requiresManualTiebreak = false,
        public bool $manualTiebreakApplied = false,
        public ?int $manualPosition = null,
        public bool $eligibleForQualification = true,
        public string $groupPlayerStatus = 'active',
    ) {}

    public function played(): int
    {
        return $this->won + $this->lost;
    }
}
