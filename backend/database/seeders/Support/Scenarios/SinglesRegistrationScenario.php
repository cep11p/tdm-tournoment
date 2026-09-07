<?php

namespace Database\Seeders\Support\Scenarios;

use App\Enums\CompetitionType;
use App\Models\Tournament;
use Database\Seeders\Support\DemoCompetitionConfig;
use Database\Seeders\Support\DemoPlayerCatalog;
use Database\Seeders\Support\DemoScenarioRunner;

final class SinglesRegistrationScenario
{
    public const COMPETITION_NAME = 'Singles — Inscripción';

    /**
     * @var list<int>
     */
    public const CHECKED_IN_SEEDS = [1, 2, 3, 4, 5];

    public function __construct(
        private readonly DemoScenarioRunner $runner,
    ) {}

    public function seed(Tournament $tournament): void
    {
        $competition = $this->runner->findOrCreateCompetition(
            $tournament,
            new DemoCompetitionConfig(
                name: self::COMPETITION_NAME,
                type: CompetitionType::Singles,
            ),
        );

        if ($competition->entries()->count() < count(DemoPlayerCatalog::SINGLES_SEEDS)) {
            $this->runner->registerAllSinglesPlayers($competition);
        }

        $this->runner->syncMembersCheckedIn(
            $competition,
            $tournament,
            self::checkedInNicknames(),
        );
    }

    /**
     * @return list<string>
     */
    public static function checkedInNicknames(): array
    {
        return array_map(
            fn (int $seed): string => DemoPlayerCatalog::nicknameForSeed($seed),
            self::CHECKED_IN_SEEDS,
        );
    }
}
