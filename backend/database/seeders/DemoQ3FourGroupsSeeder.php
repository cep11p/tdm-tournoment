<?php

namespace Database\Seeders;

use Database\Seeders\Support\DemoScenarioBuilder;
use Database\Seeders\Support\DemoScenarioConfig;
use Illuminate\Database\Seeder;

class DemoQ3FourGroupsSeeder extends Seeder
{
    public function run(): void
    {
        app(DemoScenarioBuilder::class)->seed(
            new DemoScenarioConfig(
                tournamentName: 'Demo Q3 Four Groups',
                competitionName: 'Singles Q3 Play-in',
                qualifiedPerGroup: 3,
                nicknamePrefix: 'q3',
                groups: [
                    [
                        'name' => 'Grupo A',
                        'players' => [
                            'A1 Esperado',
                            'A2 Esperado',
                            'A3 Esperado',
                            'A4 No Clasifica',
                        ],
                    ],
                    [
                        'name' => 'Grupo B',
                        'players' => [
                            'B1 Esperado',
                            'B2 Esperado',
                            'B3 Esperado',
                            'B4 No Clasifica',
                        ],
                    ],
                    [
                        'name' => 'Grupo C',
                        'players' => [
                            'C1 Esperado',
                            'C2 Esperado',
                            'C3 Esperado',
                            'C4 No Clasifica',
                        ],
                    ],
                    [
                        'name' => 'Grupo D',
                        'players' => [
                            'D1 Esperado',
                            'D2 Esperado',
                            'D3 Esperado',
                            'D4 No Clasifica',
                        ],
                    ],
                ],
                bracketShouldSucceed: true,
                bracketNote: '12 clasificados → bracket de 16 con 4 BYEs y 4 play-ins',
            ),
            $this->command
        );
    }
}
