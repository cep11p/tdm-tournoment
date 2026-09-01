<?php

namespace Database\Seeders;

use App\Enums\TournamentStatus;
use Database\Seeders\Support\DemoResultRecorder;
use Database\Seeders\Support\DemoScenarioRunner;
use Database\Seeders\Support\Scenarios\SinglesGroupsInProgressScenario;
use Database\Seeders\Support\Scenarios\SinglesKnockoutInProgressScenario;
use Database\Seeders\Support\Scenarios\SinglesRegistrationScenario;
use Illuminate\Database\Seeder;

class DemoTournamentSeeder extends Seeder
{
    public function run(): void
    {
        $runner = app(DemoScenarioRunner::class);
        $results = app(DemoResultRecorder::class);

        $tournament = $runner->findOrCreateTournament(
            DemoScenarioRunner::TOURNAMENT_ACTIVE,
            TournamentStatus::InProgress,
        );

        (new SinglesRegistrationScenario($runner))->seed($tournament);
        (new SinglesGroupsInProgressScenario($runner, $results))->seed($tournament);
        (new SinglesKnockoutInProgressScenario($runner, $results))->seed($tournament);
    }
}
