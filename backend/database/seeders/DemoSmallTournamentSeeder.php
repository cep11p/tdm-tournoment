<?php

namespace Database\Seeders;

use Database\Seeders\Support\DemoScenarioBuilder;
use Database\Seeders\Support\DemoScenarioConfig;
use Illuminate\Database\Seeder;

class DemoSmallTournamentSeeder extends Seeder
{
    public function run(): void
    {
        app(DemoScenarioBuilder::class)->seed(
            new DemoScenarioConfig(
                tournamentName: 'Demo Small',
                competitionName: 'Singles Small',
                qualifiedPerGroup: 2,
                nicknamePrefix: 'small',
                groups: [
                    [
                        'name' => 'Grupo A',
                        'players' => ['Small A1', 'Small A2', 'Small A3', 'Small A4'],
                    ],
                    [
                        'name' => 'Grupo B',
                        'players' => ['Small B1', 'Small B2', 'Small B3', 'Small B4'],
                    ],
                ],
                bracketShouldSucceed: true,
                bracketNote: 'Semifinales (4 clasificados)',
            ),
            $this->command
        );
    }
}
