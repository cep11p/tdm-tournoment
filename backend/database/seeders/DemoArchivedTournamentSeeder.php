<?php

namespace Database\Seeders;

use App\Actions\Tournament\CloseTournamentAction;
use App\Enums\TournamentStatus;
use App\Models\Tournament;
use Database\Seeders\Support\DemoResultRecorder;
use Database\Seeders\Support\DemoScenarioRunner;
use Database\Seeders\Support\Scenarios\DoublesCompletedScenario;
use Database\Seeders\Support\Scenarios\SinglesCompletedScenario;
use Illuminate\Database\Seeder;

class DemoArchivedTournamentSeeder extends Seeder
{
    public function run(): void
    {
        $runner = app(DemoScenarioRunner::class);
        $results = app(DemoResultRecorder::class);

        $tournament = $runner->findOrCreateTournament(
            DemoScenarioRunner::TOURNAMENT_ARCHIVED,
            TournamentStatus::InProgress,
        );

        (new SinglesCompletedScenario($runner, $results))->seed($tournament);
        (new DoublesCompletedScenario($runner, $results))->seed($tournament);

        $tournament->refresh();

        if ($tournament->status === TournamentStatus::Finished) {
            return;
        }

        app(CloseTournamentAction::class)($tournament);
    }
}
