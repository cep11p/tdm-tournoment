<?php

namespace Database\Seeders\Support\Scenarios;

use App\Enums\CompetitionFormat;
use App\Enums\CompetitionType;
use App\Enums\ThirdPlaceMode;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\TeamTie;
use App\Models\TeamTieFormat;
use App\Models\Tournament;
use Database\Seeders\Support\DemoCompetitionConfig;
use Database\Seeders\Support\DemoResultRecorder;
use Database\Seeders\Support\DemoScenarioRunner;
use Illuminate\Support\Collection;

final class TeamKnockoutInProgressScenario
{
    public const COMPETITION_NAME = 'Equipos — Eliminatoria';

    public const TEAM_TIE_FORMAT_NAME = 'Copa 5';

    public const GROUP_NAME = 'Grupo A';

    public const TEAM_ANDES = 'Andes';

    public const TEAM_PATAGONIA = 'Patagonia';

    public const TEAM_LAGOS = 'Lagos';

    public const TEAM_VALLE = 'Valle';

    /**
     * @var array<string, list<string>>
     */
    private const TEAMS = [
        self::TEAM_ANDES => [
            'demo-carlos-perez',
            'demo-martin-castro',
            'demo-pablo-romero',
            'demo-felipe-rios',
        ],
        self::TEAM_PATAGONIA => [
            'demo-juan-gomez',
            'demo-luis-lopez',
            'demo-andres-vega',
            'demo-sergio-aguilar',
        ],
        self::TEAM_LAGOS => [
            'demo-pedro-ruiz',
            'demo-nicolas-torres',
            'demo-javier-soto',
            'demo-bruno-medina',
        ],
        self::TEAM_VALLE => [
            'demo-marcos-diaz',
            'demo-diego-silva',
            'demo-tomas-herrera',
            'demo-hernan-molina',
        ],
    ];

    public function __construct(
        private readonly DemoScenarioRunner $runner,
        private readonly DemoResultRecorder $results,
    ) {}

    public function seed(Tournament $tournament): void
    {
        $formatId = TeamTieFormat::query()
            ->where('name', self::TEAM_TIE_FORMAT_NAME)
            ->where('active', true)
            ->value('id');

        if ($formatId === null) {
            throw new \RuntimeException(sprintf(
                'No existe el formato de enfrentamiento activo "%s".',
                self::TEAM_TIE_FORMAT_NAME,
            ));
        }

        $competition = $this->runner->findOrCreateCompetition(
            $tournament,
            new DemoCompetitionConfig(
                name: self::COMPETITION_NAME,
                type: CompetitionType::Team,
                format: CompetitionFormat::GroupsKnockout,
                qualifiedPerGroup: 2,
                groupStageBestOf: 3,
                knockoutStageBestOf: 3,
                semifinalBestOf: 3,
                finalBestOf: 5,
                thirdPlaceMode: ThirdPlaceMode::Shared,
                pointsPerSet: 11,
                teamSize: 4,
                teamTieFormatId: (int) $formatId,
            ),
        );

        if ($this->runner->competitionHasBracket($competition)) {
            return;
        }

        $entries = $this->registerTeams($competition);

        $group = $this->runner->findOrCreateGroup($competition, self::GROUP_NAME);
        $this->runner->assignEntriesToGroup($group, array_values($entries));
        $this->runner->generateGroupRoundRobinIfNeeded($group);

        $this->finishGroupTies($group->teamTies()->orderBy('id')->get(), $entries);

        $this->results->createBracket($competition);
        $this->seedFinalInProgress($competition, $entries);
    }

    /**
     * @return array<string, CompetitionEntry>
     */
    private function registerTeams(Competition $competition): array
    {
        $entries = [];

        foreach (self::TEAMS as $name => $nicknames) {
            $entries[$name] = $this->runner->registerTeamByNicknames($competition, $name, $nicknames);
        }

        return $entries;
    }

    /**
     * @param  Collection<int, TeamTie>  $teamTies
     * @param  array<string, CompetitionEntry>  $entries
     */
    private function finishGroupTies(Collection $teamTies, array $entries): void
    {
        $andes = $entries[self::TEAM_ANDES];
        $patagonia = $entries[self::TEAM_PATAGONIA];
        $valle = $entries[self::TEAM_VALLE];

        $andesVsValle = $this->results->findTeamTieBetweenEntries($teamTies, $andes->id, $valle->id);
        $andesVsPatagonia = $this->results->findTeamTieBetweenEntries($teamTies, $andes->id, $patagonia->id);

        $this->results->finishTeamTieSlots($andesVsValle, [
            1 => $andes->id,
            2 => $andes->id,
            3 => $andes->id,
        ]);

        $this->results->finishTeamTieSlots($andesVsPatagonia, [
            1 => $patagonia->id,
            2 => $andes->id,
            3 => $andes->id,
            4 => $andes->id,
        ]);

        foreach ($teamTies as $teamTie) {
            if (in_array((int) $teamTie->id, [(int) $andesVsValle->id, (int) $andesVsPatagonia->id], true)) {
                continue;
            }

            $this->results->finishTeamTieByBetterSeed($teamTie->fresh());
        }
    }

    /**
     * @param  array<string, CompetitionEntry>  $entries
     */
    private function seedFinalInProgress(Competition $competition, array $entries): void
    {
        $final = TeamTie::query()
            ->where('competition_id', $competition->id)
            ->where('round', 'Final')
            ->firstOrFail();

        $this->results->finishTeamTiePartial($final, [
            1 => $entries[self::TEAM_ANDES]->id,
        ]);
    }
}
