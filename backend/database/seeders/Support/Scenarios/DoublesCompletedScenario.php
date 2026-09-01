<?php

namespace Database\Seeders\Support\Scenarios;

use App\Enums\CompetitionType;
use App\Enums\ThirdPlaceMode;
use App\Models\Tournament;
use Database\Seeders\Support\DemoCompetitionConfig;
use Database\Seeders\Support\DemoResultRecorder;
use Database\Seeders\Support\DemoScenarioRunner;

final class DoublesCompletedScenario
{
    public const COMPETITION_NAME = 'Dobles — Finalizada';

    public function __construct(
        private readonly DemoScenarioRunner $runner,
        private readonly DemoResultRecorder $results,
    ) {}

    public function seed(Tournament $tournament): void
    {
        $competition = $this->runner->findOrCreateCompetition(
            $tournament,
            new DemoCompetitionConfig(
                name: self::COMPETITION_NAME,
                type: CompetitionType::Doubles,
                categorySlug: 'libre',
                thirdPlaceMode: ThirdPlaceMode::Playoff,
                finalBestOf: 5,
            ),
        );

        if ($this->runner->competitionHasBracket($competition)) {
            return;
        }

        $entries = $this->runner->registerDoublesPairs($competition);

        $groupA = $this->runner->findOrCreateGroup($competition, 'Grupo A');
        $groupB = $this->runner->findOrCreateGroup($competition, 'Grupo B');

        $this->runner->assignEntriesToGroup($groupA, [$entries[1], $entries[2]]);
        $this->runner->assignEntriesToGroup($groupB, [$entries[3], $entries[4]]);

        $this->runner->generateGroupRoundRobinIfNeeded($groupA);
        $this->runner->generateGroupRoundRobinIfNeeded($groupB);

        $this->results->finishAllGroupGamesByBetterSeed($groupA);
        $this->results->finishAllGroupGamesByBetterSeed($groupB);

        $this->results->completeCompetitionBracket($competition);
    }
}
