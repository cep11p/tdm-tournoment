<?php

namespace Database\Seeders\Support;

final readonly class DemoScenarioConfig
{
    /**
     * @param  array<int, array{name: string, players: array<int, string>}>  $groups
     */
    public function __construct(
        public string $tournamentName,
        public string $competitionName,
        public int $qualifiedPerGroup,
        public string $nicknamePrefix,
        public array $groups,
        public bool $bracketShouldSucceed,
        public ?string $bracketNote = null,
    ) {}

    public function groupCount(): int
    {
        return count($this->groups);
    }

    public function totalPlayers(): int
    {
        $total = 0;

        foreach ($this->groups as $group) {
            $total += count($group['players']);
        }

        return $total;
    }

    public function estimatedQualifiers(): int
    {
        $total = 0;

        foreach ($this->groups as $group) {
            $total += min($this->qualifiedPerGroup, count($group['players']));
        }

        return $total;
    }
}
