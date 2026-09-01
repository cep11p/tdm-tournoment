<?php

namespace Database\Seeders\Support\Scenarios;

use App\Enums\CompetitionType;
use App\Enums\ThirdPlaceMode;
use App\Models\Group;
use App\Models\Tournament;
use Database\Seeders\Support\DemoCompetitionConfig;
use Database\Seeders\Support\DemoPlayerCatalog;
use Database\Seeders\Support\DemoResultRecorder;
use Database\Seeders\Support\DemoScenarioRunner;

final class SinglesGroupsInProgressScenario
{
    public const COMPETITION_NAME = 'Singles — Grupos en progreso';

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

        if ($this->runner->competitionHasSchedule($competition)) {
            return;
        }

        $entries = $this->runner->registerAllSinglesPlayers($competition);

        $groupA = $this->runner->findOrCreateGroup($competition, 'Grupo A');
        $groupB = $this->runner->findOrCreateGroup($competition, 'Grupo B');

        $this->runner->assignEntriesToGroup($groupA, $this->runner->entriesForNicknames($entries, self::GROUP_A_NICKNAMES));
        $this->runner->assignEntriesToGroup($groupB, $this->runner->entriesForNicknames($entries, self::GROUP_B_NICKNAMES));

        $this->runner->generateGroupRoundRobinIfNeeded($groupA);
        $this->runner->generateGroupRoundRobinIfNeeded($groupB);

        $this->finishPartialGroupA($groupA);
        $this->finishGroupBWithManualTiebreak($groupB, $entries);
    }

    private function finishPartialGroupA(Group $group): void
    {
        $games = $group->games()->orderBy('id')->get();

        foreach ($games->take((int) ceil($games->count() / 2)) as $index => $game) {
            $this->results->finishGameByBetterSeed($game, $index);
        }
    }

    /**
     * @param  array<int, \App\Models\CompetitionEntry>  $entries
     */
    private function finishGroupBWithManualTiebreak(Group $group, array $entries): void
    {
        $games = $group->games()->get();
        $pattern = DemoResultRecorder::BALANCED_FOUR_SET_PATTERN;

        $juan = $entries[DemoPlayerCatalog::seedForNickname('demo-juan-gomez')];
        $marcos = $entries[DemoPlayerCatalog::seedForNickname('demo-marcos-diaz')];
        $martin = $entries[DemoPlayerCatalog::seedForNickname('demo-martin-castro')];
        $nicolas = $entries[DemoPlayerCatalog::seedForNickname('demo-nicolas-torres')];

        $this->results->finishGameWithSetScores(
            $this->results->findGameBetweenEntries($games, $juan->id, $marcos->id),
            $juan->id,
            $pattern,
        );
        $this->results->finishGameWithSetScores(
            $this->results->findGameBetweenEntries($games, $marcos->id, $martin->id),
            $marcos->id,
            $pattern,
        );
        $this->results->finishGameWithSetScores(
            $this->results->findGameBetweenEntries($games, $martin->id, $juan->id),
            $martin->id,
            $pattern,
        );
        $this->results->finishGameWithSetScores(
            $this->results->findGameBetweenEntries($games, $juan->id, $nicolas->id),
            $juan->id,
            $pattern,
        );
        $this->results->finishGameWithSetScores(
            $this->results->findGameBetweenEntries($games, $marcos->id, $nicolas->id),
            $marcos->id,
            $pattern,
        );
        $this->results->finishGameWithSetScores(
            $this->results->findGameBetweenEntries($games, $martin->id, $nicolas->id),
            $martin->id,
            $pattern,
        );
    }
}
