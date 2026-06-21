<?php

namespace Database\Seeders;

use Database\Seeders\Support\DemoScenarioBuilder;
use Database\Seeders\Support\DemoScenarioConfig;
use Illuminate\Database\Seeder;

class DemoFourGroupsSeeder extends Seeder
{
    public function run(): void
    {
        app(DemoScenarioBuilder::class)->seed(
            new DemoScenarioConfig(
                tournamentName: 'Demo Four Groups',
                competitionName: 'Singles Four Groups',
                qualifiedPerGroup: 2,
                nicknamePrefix: 'four',
                groups: [
                    [
                        'name' => 'Grupo A',
                        'players' => ['Four A1', 'Four A2', 'Four A3', 'Four A4'],
                    ],
                    [
                        'name' => 'Grupo B',
                        'players' => ['Four B1', 'Four B2', 'Four B3', 'Four B4'],
                    ],
                    [
                        'name' => 'Grupo C',
                        'players' => ['Four C1', 'Four C2', 'Four C3'],
                    ],
                    [
                        'name' => 'Grupo D',
                        'players' => ['Four D1', 'Four D2', 'Four D3'],
                    ],
                ],
                bracketShouldSucceed: true,
                bracketNote: 'Cuartos de final (8 clasificados)',
            ),
            $this->command
        );
    }
}
