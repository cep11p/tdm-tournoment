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
                        'players' => [
                            'Juan Pérez',
                            'Marcos Ríos',
                            'Diego Fernández',
                            'Tomás Acosta',
                        ],
                    ],
                    [
                        'name' => 'Grupo B',
                        'players' => [
                            'Luciano Torres',
                            'Nicolás Herrera',
                            'Franco Medina',
                            'Matías Castro',
                        ],
                    ],
                ],
                bracketShouldSucceed: true,
                bracketNote: 'Semifinales (4 clasificados)',
            ),
            $this->command
        );
    }
}
