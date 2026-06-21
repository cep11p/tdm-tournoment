<?php

namespace Database\Seeders;

use Database\Seeders\Support\DemoScenarioBuilder;
use Database\Seeders\Support\DemoScenarioConfig;
use Illuminate\Database\Seeder;

class DemoOnlyWinnersSeeder extends Seeder
{
    public function run(): void
    {
        app(DemoScenarioBuilder::class)->seed(
            new DemoScenarioConfig(
                tournamentName: 'Demo Winners',
                competitionName: 'Singles Winners',
                qualifiedPerGroup: 1,
                nicknamePrefix: 'winners',
                groups: [
                    [
                        'name' => 'Grupo A',
                        'players' => ['Win A1', 'Win A2', 'Win A3', 'Win A4'],
                    ],
                    [
                        'name' => 'Grupo B',
                        'players' => ['Win B1', 'Win B2', 'Win B3', 'Win B4'],
                    ],
                ],
                bracketShouldSucceed: true,
                bracketNote: 'Final directa (2 clasificados)',
            ),
            $this->command
        );
    }
}
