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
                        'players' => [
                            'Santiago Molina',
                            'Pablo Navarro',
                            'Rodrigo Silva',
                            'Agustín Romero',
                        ],
                    ],
                    [
                        'name' => 'Grupo B',
                        'players' => [
                            'Emiliano Vargas',
                            'Facundo Luna',
                            'Joaquín Morales',
                            'Martín Cabrera',
                        ],
                    ],
                ],
                bracketShouldSucceed: true,
                bracketNote: 'Final directa (2 clasificados)',
            ),
            $this->command
        );
    }
}
