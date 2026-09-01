<?php

namespace Database\Seeders\Support\Scenarios;

use App\Enums\CompetitionType;
use App\Enums\ThirdPlaceMode;
use App\Models\Tournament;
use Database\Seeders\Support\DemoCompetitionConfig;
use Database\Seeders\Support\DemoResultRecorder;
use Database\Seeders\Support\DemoScenarioRunner;

final class SinglesKnockoutInProgressScenario
{
    public const COMPETITION_NAME = 'Singles — Eliminatoria';

    private const GROUP_A_NICKNAMES = [
        'demo-carlos-perez',
        'demo-pedro-ruiz',
        'demo-luis-lopez',
        'demo-diego-silva',
    ];

    private const GROUP_B_NICKNAMES = [
        'demo-juan-gomez',
        'demo-marcos-diaz',
        'demo-martin-castro',
        'demo-nicolas-torres',
    ];

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
                type: CompetitionType::Singles,
                thirdPlaceMode: ThirdPlaceMode::Playoff,
                finalBestOf: 5,
            ),
        );

        if ($this->runner->competitionHasBracket($competition)) {
            return;
        }

        $entries = $this->runner->registerAllSinglesPlayers($competition);

        $groupA = $this->runner->findOrCreateGroup($competition, 'Grupo A');
        $groupB = $this->runner->findOrCreateGroup($competition, 'Grupo B');

        $this->runner->assignEntriesToGroup(
            $groupA,
            $this->runner->entriesForNicknames($entries, self::GROUP_A_NICKNAMES),
        );
        $this->runner->assignEntriesToGroup(
            $groupB,
            $this->runner->entriesForNicknames($entries, self::GROUP_B_NICKNAMES),
        );

        $this->runner->generateGroupRoundRobinIfNeeded($groupA);
        $this->runner->generateGroupRoundRobinIfNeeded($groupB);

        $this->results->finishAllGroupGamesByBetterSeed($groupA);
        $this->results->finishAllGroupGamesByBetterSeed($groupB);

        $this->results->createBracket($competition);
    }
}
