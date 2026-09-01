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

        if ($competition->entries()->count() >= count(DemoPlayerCatalog::definitions())) {
            return;
        }

        $this->runner->registerAllSinglesPlayers($competition);
    }
}
